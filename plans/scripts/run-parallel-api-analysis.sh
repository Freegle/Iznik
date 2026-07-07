#!/bin/bash

# Parallel API analysis using jscodeshift
# Uses background jobs to analyze multiple files simultaneously

echo "=== Parallel API Usage Analysis ==="
echo "Started at: $(date)"
echo ""

# Output directory
OUTPUT_DIR="api-analysis-results"
rm -rf $OUTPUT_DIR
mkdir -p $OUTPUT_DIR

# Temp directory for Vue script extraction
TEMP_DIR="/tmp/parallel-api-analysis"
rm -rf $TEMP_DIR
mkdir -p $TEMP_DIR

# Number of parallel jobs
MAX_PARALLEL=10
job_count=0

# Function to wait for jobs to complete if we hit the limit
wait_for_jobs() {
    while [ $(jobs -r | wc -l) -ge $MAX_PARALLEL ]; do
        sleep 0.1
    done
}

# Function to analyze a JS file
analyze_js_file() {
    local file=$1
    local output_file=$2

    result=$(jscodeshift -t analyze-all-api-calls.js --dry --silent "$file" 2>/dev/null)
    if [ ! -z "$result" ]; then
        echo "$result" > "$output_file"
    fi
}

# Function to analyze a Vue file
analyze_vue_file() {
    local vue_file=$1
    local output_file=$2
    local temp_js="$TEMP_DIR/$(echo $vue_file | tr '/' '_').js"

    # Extract script section
    awk '/<script[^>]*>/{flag=1; next} /<\/script>/{flag=0} flag' "$vue_file" > "$temp_js"

    if [ -s "$temp_js" ]; then
        result=$(jscodeshift -t analyze-all-api-calls.js --dry --silent "$temp_js" 2>/dev/null)
        if [ ! -z "$result" ]; then
            # Replace temp path with original Vue file path
            result=$(echo "$result" | sed "s|$temp_js|$vue_file|g")
            echo "$result" > "$output_file"
        fi
    fi
    rm -f "$temp_js"
}

echo "Phase 1: Analyzing FD (iznik-nuxt3)..."
echo "========================================="

# Process FD JS files in parallel
echo "Processing JavaScript files..."
fd_js_count=0
for js_file in $(find iznik-nuxt3 -name "*.js" -not -path "*/node_modules/*" -not -path "*/.nuxt/*" 2>/dev/null); do
    wait_for_jobs

    output_file="$OUTPUT_DIR/fd_js_$(echo $js_file | tr '/' '_').json"
    analyze_js_file "$js_file" "$output_file" &

    fd_js_count=$((fd_js_count + 1))
    if [ $((fd_js_count % 50)) -eq 0 ]; then
        echo "  Launched analysis for $fd_js_count JS files..."
    fi
done

echo "  Total FD JS files: $fd_js_count"

# Process FD Vue files in parallel
echo "Processing Vue files..."
fd_vue_count=0
for vue_file in $(find iznik-nuxt3 -name "*.vue" -not -path "*/node_modules/*" -not -path "*/.nuxt/*" 2>/dev/null); do
    wait_for_jobs

    output_file="$OUTPUT_DIR/fd_vue_$(echo $vue_file | tr '/' '_').json"
    analyze_vue_file "$vue_file" "$output_file" &

    fd_vue_count=$((fd_vue_count + 1))
    if [ $((fd_vue_count % 50)) -eq 0 ]; then
        echo "  Launched analysis for $fd_vue_count Vue files..."
    fi
done

echo "  Total FD Vue files: $fd_vue_count"

# Wait for all FD jobs to complete
echo "Waiting for FD analysis to complete..."
wait

echo ""
echo "Phase 2: Analyzing MT (iznik-nuxt3-modtools)..."
echo "================================================"

# Process MT JS files in parallel
echo "Processing JavaScript files..."
mt_js_count=0
for js_file in $(find iznik-nuxt3-modtools -name "*.js" -not -path "*/node_modules/*" -not -path "*/.nuxt/*" 2>/dev/null); do
    wait_for_jobs

    output_file="$OUTPUT_DIR/mt_js_$(echo $js_file | tr '/' '_').json"
    analyze_js_file "$js_file" "$output_file" &

    mt_js_count=$((mt_js_count + 1))
    if [ $((mt_js_count % 50)) -eq 0 ]; then
        echo "  Launched analysis for $mt_js_count JS files..."
    fi
done

echo "  Total MT JS files: $mt_js_count"

# Process MT Vue files in parallel
echo "Processing Vue files..."
mt_vue_count=0
for vue_file in $(find iznik-nuxt3-modtools -name "*.vue" -not -path "*/node_modules/*" -not -path "*/.nuxt/*" 2>/dev/null); do
    wait_for_jobs

    output_file="$OUTPUT_DIR/mt_vue_$(echo $vue_file | tr '/' '_').json"
    analyze_vue_file "$vue_file" "$output_file" &

    mt_vue_count=$((mt_vue_count + 1))
    if [ $((mt_vue_count % 50)) -eq 0 ]; then
        echo "  Launched analysis for $mt_vue_count Vue files..."
    fi
done

echo "  Total MT Vue files: $mt_vue_count"

# Wait for all jobs to complete
echo "Waiting for all analysis to complete..."
wait

# Clean up temp directory
rm -rf $TEMP_DIR

echo ""
echo "Phase 3: Combining results..."
echo "=============================="

# Combine FD results
echo "[" > fd-complete-analysis.json
first=true
for result_file in $OUTPUT_DIR/fd_*.json; do
    if [ -f "$result_file" ]; then
        if [ "$first" = false ]; then
            echo "," >> fd-complete-analysis.json
        fi
        cat "$result_file" >> fd-complete-analysis.json
        first=false
    fi
done
echo "]" >> fd-complete-analysis.json

# Combine MT results
echo "[" > mt-complete-analysis.json
first=true
for result_file in $OUTPUT_DIR/mt_*.json; do
    if [ -f "$result_file" ]; then
        if [ "$first" = false ]; then
            echo "," >> mt-complete-analysis.json
        fi
        cat "$result_file" >> mt-complete-analysis.json
        first=false
    fi
done
echo "]" >> mt-complete-analysis.json

echo ""
echo "Phase 4: Generating comprehensive report..."
echo "==========================================="

# Generate comprehensive markdown report
python3 << 'PYTHON_SCRIPT' > comprehensive-api-analysis.md
import json
from collections import defaultdict, Counter

def load_json_safely(filename):
    try:
        with open(filename, 'r') as f:
            return json.load(f)
    except:
        return []

def analyze_data(data, project_name):
    api_calls = defaultdict(list)
    store_methods = defaultdict(set)
    endpoint_files = defaultdict(set)

    for entry in data:
        if not entry:
            continue

        file_path = entry.get('file', '')

        # Collect API calls
        for api_call in entry.get('apiCalls', []):
            obj = api_call.get('object', '')
            method = api_call.get('method', '')
            endpoint = api_call.get('endpoint', f"/{obj}")
            line = api_call.get('line', 'unknown')

            key = f"{endpoint} ({method})"
            api_calls[key].append({
                'file': file_path,
                'line': line,
                'object': obj,
                'method': method
            })
            endpoint_files[endpoint].add(file_path)

        # Collect store usage
        for store_name, info in entry.get('stores', {}).items():
            for method_info in info.get('methods', []):
                method_name = method_info.get('name', '')
                if method_name:
                    store_methods[f"{store_name}.{method_name}"].add(file_path)

    return api_calls, store_methods, endpoint_files

# Load data
fd_data = load_json_safely('fd-complete-analysis.json')
mt_data = load_json_safely('mt-complete-analysis.json')

# Analyze
fd_api, fd_stores, fd_endpoints = analyze_data(fd_data, 'FD')
mt_api, mt_stores, mt_endpoints = analyze_data(mt_data, 'MT')

# Generate report
print("# Comprehensive V1 API Usage Analysis")
print()
print("## Summary Statistics")
print()
print(f"- **FD Files Analyzed**: {len(fd_data)}")
print(f"- **MT Files Analyzed**: {len(mt_data)}")
print(f"- **Unique API Endpoints in FD**: {len(fd_endpoints)}")
print(f"- **Unique API Endpoints in MT**: {len(mt_endpoints)}")
print()

print("## API Endpoints by Usage")
print()

# Find exclusive endpoints
fd_only_endpoints = set(fd_api.keys()) - set(mt_api.keys())
mt_only_endpoints = set(mt_api.keys()) - set(fd_api.keys())
shared_endpoints = set(fd_api.keys()) & set(mt_api.keys())

print("### FD-Only API Calls")
if fd_only_endpoints:
    for endpoint in sorted(fd_only_endpoints):
        calls = fd_api[endpoint]
        print(f"- **{endpoint}** ({len(calls)} calls)")
        for call in calls[:2]:  # Show first 2 examples
            print(f"  - `{call['file']}:{call['line']}`")
else:
    print("- None found")
print()

print("### MT-Only API Calls")
if mt_only_endpoints:
    for endpoint in sorted(mt_only_endpoints):
        calls = mt_api[endpoint]
        print(f"- **{endpoint}** ({len(calls)} calls)")
        for call in calls[:2]:  # Show first 2 examples
            print(f"  - `{call['file']}:{call['line']}`")
else:
    print("- None found")
print()

print("### Shared API Calls")
print(f"Total: {len(shared_endpoints)} endpoints")
print()
for endpoint in sorted(list(shared_endpoints))[:20]:  # Show first 20
    fd_count = len(fd_api[endpoint])
    mt_count = len(mt_api[endpoint])
    print(f"- **{endpoint}**: FD ({fd_count} calls), MT ({mt_count} calls)")
print()

print("## Key /messages Endpoint Analysis")
print()

messages_analysis = {}
for endpoint, calls in fd_api.items():
    if 'messages' in endpoint.lower():
        messages_analysis[f"FD: {endpoint}"] = calls

for endpoint, calls in mt_api.items():
    if 'messages' in endpoint.lower():
        messages_analysis[f"MT: {endpoint}"] = calls

if messages_analysis:
    for key in sorted(messages_analysis.keys()):
        calls = messages_analysis[key]
        print(f"### {key}")
        print(f"Total calls: {len(calls)}")
        for call in calls[:3]:
            print(f"- `{call['file']}:{call['line']}`")
        print()
else:
    print("No /messages endpoint usage found")
print()

print("## Store Method Usage Comparison")
print()

# Find store methods unique to each project
fd_only_methods = set(fd_stores.keys()) - set(mt_stores.keys())
mt_only_methods = set(mt_stores.keys()) - set(fd_stores.keys())

if fd_only_methods:
    print("### Store Methods Only in FD")
    for method in sorted(list(fd_only_methods))[:10]:
        print(f"- {method}")
    print()

if mt_only_methods:
    print("### Store Methods Only in MT")
    for method in sorted(list(mt_only_methods))[:10]:
        print(f"- {method}")
    print()

PYTHON_SCRIPT

echo ""
echo "=== Analysis Complete ==="
echo "Completed at: $(date)"
echo ""
echo "Output files created:"
echo "  - fd-complete-analysis.json (Detailed FD results)"
echo "  - mt-complete-analysis.json (Detailed MT results)"
echo "  - comprehensive-api-analysis.md (Summary report)"
echo "  - $OUTPUT_DIR/ (Individual file results)"
echo ""
echo "Files analyzed:"
echo "  - FD: $fd_js_count JS files, $fd_vue_count Vue files"
echo "  - MT: $mt_js_count JS files, $mt_vue_count Vue files"
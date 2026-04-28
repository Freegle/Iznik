const { execSync } = require("child_process");
const http = require("http");
const https = require("https");
const fs = require("fs");
const path = require("path");
const url = require("url");

// Detect which docker compose command is available
function getDockerComposeCommand() {
  try {
    execSync("docker compose version", { stdio: "ignore" });
    return "docker compose";
  } catch {
    try {
      execSync("docker-compose version", { stdio: "ignore" });
      return "docker-compose";
    } catch {
      return "docker compose"; // Default to v2 syntax
    }
  }
}
const DOCKER_COMPOSE = getDockerComposeCommand();
console.log(`Using docker compose command: ${DOCKER_COMPOSE}`);

// Status cache for all services
const statusCache = new Map();
let lastFullCheck = 0;
const CHECK_INTERVAL = 30000; // 30 seconds
let isRunningChecks = false;

// Test execution tracking
let testStatus = {
  status: "idle", // 'idle', 'running', 'completed', 'failed'
  message: "",
  logs: "",
  success: false,
  startTime: null,
  endTime: null,
};

// Individual test statuses for different test suites
const testStatuses = {
  phpTests: null,
  goTests: null,
  laravelTests: null,
  playwrightTests: null,
};

// Health check messages for services
const healthCheckMessages = {
  "freegle-traefik": "Reverse proxy dashboard accessible (wget /dashboard/)",
  "freegle-percona": "MySQL database query test passed (SELECT 1)",
  "freegle-postgres": "PostgreSQL database query test passed (SELECT 1)",
  "freegle-redis": "Cache service ping test successful (redis-cli ping)",
  "freegle-beanstalkd": "Job queue port connection test passed (nc -z 11300)",
  "freegle-spamassassin":
    "Email filtering port connection test passed (nc -z 783)",
  "freegle-tusd": "File upload endpoint responding (wget /tus/)",
  "freegle-phpmyadmin": "Database management interface responding (HTTP)",
  "freegle-mailpit": "Email testing interface responding (HTTP)",
  "freegle-apiv1": "API config endpoint responding (curl /api/config)",
  "freegle-apiv2": "API online endpoint responding (curl /api/online)",
  "freegle-batch": "Laravel batch processor ready (php artisan)",
  "freegle-delivery":
    "wsrv.nl image transformation service responding (wget /)",
  "freegle-playwright": "Playwright test container ready for test execution",
};

// Service definitions
const services = [
  // Freegle Components
  {
    id: "freegle-dev-local",
    container: "freegle-dev-local",
    checkType: "freegle-component",
    category: "freegle",
  },
  {
    id: "freegle-dev-live",
    container: "freegle-dev-live",
    checkType: "freegle-component",
    category: "freegle",
  },
  {
    id: "freegle-prod-local",
    container: "freegle-prod-local",
    checkType: "freegle-component",
    category: "freegle",
  },
  {
    id: "modtools-dev-local",
    container: "modtools-dev-local",
    checkType: "freegle-component",
    category: "freegle",
  },
  {
    id: "modtools-dev-live",
    container: "modtools-dev-live",
    checkType: "freegle-component",
    category: "freegle",
  },
  {
    id: "modtools-prod-local",
    container: "modtools-prod-local",
    checkType: "freegle-component",
    category: "freegle",
  },
  {
    id: "apiv1",
    container: "freegle-apiv1",
    checkType: "api-service",
    category: "freegle",
  },
  {
    id: "apiv2",
    container: "freegle-apiv2",
    checkType: "api-service",
    category: "freegle",
  },
  {
    id: "batch",
    container: "freegle-batch",
    checkType: "batch-service",
    category: "freegle",
  },

  // Development Tools
  {
    id: "phpmyadmin",
    container: "freegle-phpmyadmin",
    checkType: "dev-tool",
    category: "dev",
  },
  {
    id: "mailpit",
    container: "freegle-mailpit",
    checkType: "dev-tool",
    category: "dev",
  },
  {
    id: "loki",
    container: "freegle-loki",
    checkType: "loki",
    category: "dev",
  },
  {
    id: "grafana",
    container: "freegle-grafana",
    checkType: "dev-tool",
    category: "dev",
  },
  {
    id: "playwright",
    container: "freegle-playwright",
    checkType: "playwright",
    category: "dev",
  },

  // Infrastructure Components
  {
    id: "percona",
    container: "freegle-percona",
    checkType: "mysql",
    category: "infra",
  },
  {
    id: "postgres",
    container: "freegle-postgres",
    checkType: "postgres",
    category: "infra",
  },
  {
    id: "redis",
    container: "freegle-redis",
    checkType: "redis",
    category: "infra",
  },
  {
    id: "mjml",
    container: "freegle-mjml",
    checkType: "mjml",
    category: "infra",
  },
  {
    id: "beanstalkd",
    container: "freegle-beanstalkd",
    checkType: "beanstalkd",
    category: "infra",
  },
  {
    id: "spamassassin",
    container: "freegle-spamassassin",
    checkType: "spamassassin",
    category: "infra",
  },
  {
    id: "traefik",
    container: "freegle-traefik",
    checkType: "traefik",
    category: "infra",
  },
  {
    id: "tusd",
    container: "freegle-tusd",
    checkType: "tusd",
    category: "infra",
  },
  {
    id: "delivery",
    container: "freegle-delivery",
    checkType: "delivery",
    category: "infra",
  },

  // MCP Support Tools (privacy-preserving log analysis)
  {
    id: "mcp-sanitizer",
    container: "freegle-mcp-sanitizer",
    checkType: "container-only",
    category: "backend",
  },
  {
    id: "mcp-interface",
    container: "freegle-mcp-interface",
    checkType: "container-only",
    category: "backend",
  },
  {
    id: "mcp-pseudonymizer",
    container: "freegle-mcp-pseudonymizer",
    checkType: "container-only",
    category: "backend",
  },
];

// HTTP Agent with keep-alive for better performance
const httpAgent = new http.Agent({
  keepAlive: true,
  maxSockets: 10,
  timeout: 3000,
});

const httpsAgent = new https.Agent({
  keepAlive: true,
  maxSockets: 10,
  timeout: 3000,
  rejectUnauthorized: false,
});

// Improved fetch implementation with connection pooling
async function fetch(targetUrl) {
  return new Promise((resolve, reject) => {
    const isHttps = targetUrl.startsWith("https:");
    const client = isHttps ? https : http;
    const agent = isHttps ? httpsAgent : httpAgent;

    const req = client.get(targetUrl, { agent }, (res) => {
      resolve({
        ok: res.statusCode >= 200 && res.statusCode < 300,
        status: res.statusCode,
        statusText: res.statusMessage,
      });
    });

    req.on("error", reject);
    req.setTimeout(3000, () => {
      req.destroy();
      reject(new Error("Request timeout"));
    });
  });
}

// Fast Docker stats using single command
async function getAllContainerStats() {
  try {
    // Get all container info in one command with start time
    const output = execSync(
      `docker ps -a --format "{{.Names}}\t{{.Status}}\t{{.State}}\t{{.CreatedAt}}"`,
      {
        encoding: "utf8",
        timeout: 5000,
      }
    );

    const containers = {};
    const lines = output.split("\n");

    for (const line of lines) {
      if (line.trim()) {
        const parts = line.split("\t");
        if (parts.length >= 4) {
          const name = parts[0].trim();
          const status = parts[1].trim();
          const state = parts[2].trim();
          const createdAt = parts[3].trim();

          if (name && (name.startsWith("freegle-") || name.startsWith("modtools-"))) {
            // Get more detailed start time using docker inspect
            let startTime = null;
            try {
              const startTimeOutput = execSync(
                `docker inspect --format='{{.State.StartedAt}}' ${name}`,
                {
                  encoding: "utf8",
                  timeout: 2000,
                }
              ).trim();
              if (
                startTimeOutput &&
                startTimeOutput !== "<no value>" &&
                !startTimeOutput.includes("Error")
              ) {
                startTime = new Date(startTimeOutput);
              }
            } catch (inspectError) {
              // Fallback to created time if StartedAt fails
              try {
                startTime = new Date(createdAt);
              } catch (parseError) {
                startTime = null;
              }
            }

            containers[name] = {
              status: state === "running" ? "running" : "stopped",
              fullStatus: status,
              startTime: startTime,
              createdAt: createdAt,
            };
          }
        }
      }
    }

    return containers;
  } catch (error) {
    console.error("Error getting container stats:", error);
    return {};
  }
}

// Check if all services are online
async function checkAllServicesOnline() {
  try {
    const serviceStatuses = await Promise.all(
      services.map(async (service) => {
        const status = await checkServiceStatus(service);
        return { id: service.id, online: status.status === "success" };
      })
    );

    const offlineServices = serviceStatuses.filter((s) => !s.online);
    const onlineCount = serviceStatuses.length - offlineServices.length;

    if (offlineServices.length > 0) {
      return {
        success: false,
        message: `${
          offlineServices.length
        } services are not online (${onlineCount}/${
          serviceStatuses.length
        } online): ${offlineServices.map((s) => s.id).join(", ")}`,
      };
    }

    return { success: true, message: "All services are online" };
  } catch (error) {
    return {
      success: false,
      message: `Failed to check service statuses: ${error.message}`,
    };
  }
}

// Background status checker using bulk Docker API
async function checkServiceStatus(service) {
  try {
    const allStats = await getAllContainerStats();
    const containerInfo = allStats[service.container];

    if (!containerInfo) {
      return { status: "failed", message: "Container not found" };
    }

    if (containerInfo.status === "running") {
      // For Freegle components, check if they are actually serving pages
      if (service.checkType === "freegle-component") {
        try {
          let testUrl, testDescription;

          if (service.id === "freegle-dev-local") {
            testUrl = "http://freegle-dev-local:3002/";
            testDescription = "Freegle Dev (Local) site responding";
          } else if (service.id === "freegle-dev-live") {
            testUrl = "http://freegle-dev-live:3002/";
            testDescription = "Freegle Dev (Live) site responding";
          } else if (service.id === "freegle-prod-local") {
            testUrl = "http://freegle-prod-local:3003/";
            testDescription = "Freegle Prod site responding";
          } else if (service.id === "modtools-dev-local") {
            testUrl = "http://modtools-dev-local:3000/";
            testDescription = "ModTools Dev site responding";
          } else if (service.id === "modtools-dev-live") {
            testUrl = "http://modtools-dev-live:3000/";
            testDescription = "ModTools Dev Live site responding";
          } else if (service.id === "modtools-prod-local") {
            testUrl = "http://modtools-prod-local:3001/";
            testDescription = "ModTools Prod site responding";
          }

          if (testUrl) {
            // Try to fetch the page to verify it's actually working
            try {
              const response = await fetch(testUrl);
              if (response.ok) {
                return {
                  status: "success",
                  message: `${testDescription} (HTTP ${response.status})`,
                };
              } else if (response.status === 502 || response.status === 503) {
                return {
                  status: "starting",
                  message: `Service building/starting (HTTP ${response.status})`,
                };
              } else {
                return { status: "failed", message: `HTTP ${response.status}` };
              }
            } catch (fetchError) {
              // If fetch fails, check logs for more context
              if (
                fetchError.code === "ECONNREFUSED" ||
                fetchError.message.includes("ECONNREFUSED")
              ) {
                try {
                  // Get full logs for production container to save to artifact
                  const tailLines = service.id === "freegle-prod" ? 1000 : 20;
                  const logs = execSync(
                    `docker logs ${service.container} --tail=${tailLines}`,
                    {
                      encoding: "utf8",
                      timeout: 3000,
                      maxBuffer: 10 * 1024 * 1024,
                    }
                  );

                  // Save production container logs to artifact file
                  if (service.id === "freegle-prod") {
                    try {
                      const artifactPath = "/tmp/freegle-prod-build-logs.txt";
                      fs.writeFileSync(
                        artifactPath,
                        `=== Production Container Build Logs ===\n` +
                          `Time: ${new Date().toISOString()}\n` +
                          `Container: ${service.container}\n` +
                          `Status: Building/Failed\n` +
                          `\n${logs}`
                      );
                      console.log(`Saved production logs to ${artifactPath}`);
                    } catch (saveError) {
                      console.warn(
                        "Failed to save production logs:",
                        saveError.message
                      );
                    }
                  }

                  // Check for various build failure patterns
                  if (
                    logs.includes("ERROR") ||
                    logs.includes("Build failed") ||
                    logs.includes("✖ ") || // ESLint errors
                    logs.includes("prettier/prettier") || // Prettier errors
                    logs.includes("error  Insert") ||
                    logs.includes("error  Replace") ||
                    logs.includes("buildEnd") || // Vite build errors
                    (logs.includes("problems") && logs.includes("errors"))
                  ) {
                    let error = "Build failed";

                    // Extract specific error information
                    if (logs.includes("prettier/prettier")) {
                      error = "Prettier/ESLint formatting errors";
                    } else if (logs.includes("✖ ")) {
                      const problemMatch = logs.match(
                        /✖ (\d+) problems \((\d+) errors/
                      );
                      if (problemMatch) {
                        error = `ESLint: ${problemMatch[2]} errors, ${problemMatch[1]} total problems`;
                      } else {
                        error = "ESLint errors detected";
                      }
                    } else {
                      const errorMatch = logs.match(/ERROR.*?(?=\n|$)/);
                      if (errorMatch) {
                        error = errorMatch[0].substring(0, 60) + "...";
                      }
                    }

                    return {
                      status: "failed",
                      message: `Build error: ${error}`,
                    };
                  } else {
                    // Show building status with last log line
                    const logLines = logs.trim().split("\n");
                    const lastLine =
                      logLines[logLines.length - 1] || "Building...";
                    const truncatedLine =
                      lastLine.length > 80
                        ? lastLine.substring(0, 80) + "..."
                        : lastLine;
                    return {
                      status: "starting",
                      message: `Building: ${truncatedLine}`,
                    };
                  }
                } catch (logError) {
                  return {
                    status: "starting",
                    message: "Service building/starting (connection refused)",
                  };
                }
              } else {
                return { status: "failed", message: fetchError.message };
              }
            }
          } else {
            // Fallback for unknown components
            return { status: "success", message: "Container running" };
          }
        } catch (error) {
          return { status: "failed", message: error.message };
        }
      } else if (service.checkType === "playwright") {
        // For Playwright, verify the container can execute test commands
        try {
          const testCommand = `docker exec ${service.container} npx playwright --version`;
          const result = execSync(testCommand, {
            encoding: "utf8",
            timeout: 5000,
          });
          return {
            status: "success",
            message: `Playwright ready for tests (${result.trim()})`,
          };
        } catch (error) {
          return {
            status: "failed",
            message: `Playwright not ready: ${error.message}`,
          };
        }
      } else {
        return {
          status: "success",
          message:
            healthCheckMessages[service.container] ||
            "Container running and healthy",
        };
      }
    } else {
      return { status: "failed", message: `Container ${containerInfo.status}` };
    }
  } catch (error) {
    return { status: "failed", message: error.message };
  }
}

// Background check runner
async function runBackgroundChecks() {
  const now = Date.now();
  if (now - lastFullCheck < CHECK_INTERVAL || isRunningChecks) return;

  isRunningChecks = true;
  console.log("Running background status checks...");
  lastFullCheck = now;

  // Get all data in bulk for efficiency
  const allStats = await getAllContainerStats();
  const allCpu = await getAllCpuUsage();

  for (const service of services) {
    try {
      const containerInfo = allStats[service.container];
      const cpu = allCpu[service.container] || 0;

      if (!containerInfo) {
        statusCache.set(service.id, {
          status: "failed",
          message: "Container not found",
          timestamp: now,
          cpu: 0,
          startTime: null,
          uptime: null,
        });
        continue;
      }

      let status, message;
      if (containerInfo.status === "running") {
        // For Freegle components, check if they are actually serving pages
        if (service.checkType === "freegle-component") {
          try {
            let testUrl, testDescription;

            if (service.id === "freegle-dev-local") {
              testUrl = "http://freegle-dev-local:3002/";
              testDescription = "Freegle Dev (Local) site responding";
            } else if (service.id === "freegle-dev-live") {
              testUrl = "http://freegle-dev-live:3002/";
              testDescription = "Freegle Dev (Live) site responding";
            } else if (service.id === "freegle-prod-local") {
              testUrl = "http://freegle-prod-local:3003/";
              testDescription = "Freegle Prod site responding";
            } else if (service.id === "modtools-dev-local") {
              testUrl = "http://modtools-dev-local:3000/";
              testDescription = "ModTools Dev site responding";
            } else if (service.id === "modtools-dev-live") {
              testUrl = "http://modtools-dev-live:3000/";
              testDescription = "ModTools Dev Live site responding";
            } else if (service.id === "modtools-prod-local") {
              testUrl = "http://modtools-prod-local:3001/";
              testDescription = "ModTools Prod site responding";
            }

            if (testUrl) {
              // Try to fetch the page to verify it's actually working
              try {
                const response = await fetch(testUrl);
                if (response.ok) {
                  status = "success";
                  message = `${testDescription} (HTTP ${response.status})`;
                } else if (response.status === 502 || response.status === 503) {
                  status = "starting";
                  message = `Service building/starting (HTTP ${response.status})`;
                } else {
                  status = "failed";
                  message = `HTTP ${response.status}`;
                }
              } catch (fetchError) {
                // If fetch fails, check logs for more context
                if (
                  fetchError.code === "ECONNREFUSED" ||
                  fetchError.message.includes("ECONNREFUSED")
                ) {
                  try {
                    // Get full logs for production container to save to artifact
                    const tailLines = service.id === "freegle-prod" ? 1000 : 20;
                    const logs = execSync(
                      `docker logs ${service.container} --tail=${tailLines}`,
                      {
                        encoding: "utf8",
                        timeout: 5000,
                        maxBuffer: 10 * 1024 * 1024,
                      }
                    );

                    // Save production container logs to artifact file
                    if (service.id === "freegle-prod") {
                      try {
                        const artifactPath = "/tmp/freegle-prod-build-logs.txt";
                        fs.writeFileSync(
                          artifactPath,
                          `=== Production Container Build Logs ===\n` +
                            `Time: ${new Date().toISOString()}\n` +
                            `Container: ${service.container}\n` +
                            `Status: Building/Failed\n` +
                            `\n${logs}`
                        );
                        console.log(`Saved production logs to ${artifactPath}`);
                      } catch (saveError) {
                        console.warn(
                          "Failed to save production logs:",
                          saveError.message
                        );
                      }
                    }

                    // Check for various build failure patterns
                    if (
                      logs.includes("ERROR") ||
                      logs.includes("Build failed") ||
                      logs.includes("✖ ") || // ESLint errors
                      logs.includes("prettier/prettier") || // Prettier errors
                      logs.includes("error  Insert") ||
                      logs.includes("error  Replace") ||
                      logs.includes("buildEnd") || // Vite build errors
                      (logs.includes("problems") && logs.includes("errors"))
                    ) {
                      let error = "Build failed";

                      // Extract specific error information
                      if (logs.includes("prettier/prettier")) {
                        error = "Prettier/ESLint formatting errors";
                      } else if (logs.includes("✖ ")) {
                        const problemMatch = logs.match(
                          /✖ (\d+) problems \((\d+) errors/
                        );
                        if (problemMatch) {
                          error = `ESLint: ${problemMatch[2]} errors, ${problemMatch[1]} total problems`;
                        } else {
                          error = "ESLint errors detected";
                        }
                      } else {
                        const errorMatch = logs.match(/ERROR.*?(?=\n|$)/);
                        if (errorMatch) {
                          error = errorMatch[0].substring(0, 60) + "...";
                        }
                      }

                      status = "failed";
                      message = `Build error: ${error}`;
                    } else {
                      // Show building status with last log line
                      const logLines = logs.trim().split("\n");
                      const lastLine =
                        logLines[logLines.length - 1] || "Building...";
                      const truncatedLine =
                        lastLine.length > 80
                          ? lastLine.substring(0, 80) + "..."
                          : lastLine;
                      status = "starting";
                      message = `Building: ${truncatedLine}`;
                    }
                  } catch (logError) {
                    status = "starting";
                    message = "Service building/starting (connection refused)";
                  }
                } else {
                  status = "failed";
                  message = fetchError.message;
                }
              }
            } else {
              // Fallback to log checking for unknown components
              status = "success";
              message = "Container running";
            }
          } catch (error) {
            status = "failed";
            message = error.message;
          }
        } else {
          status = "success";

          message =
            healthCheckMessages[service.container] ||
            "Container running and healthy";
        }
      } else {
        status = "failed";
        message = `Container ${containerInfo.status}`;
      }

      statusCache.set(service.id, {
        status,
        message,
        timestamp: now,
        cpu,
        startTime: containerInfo.startTime,
        uptime: containerInfo.startTime
          ? Math.floor((now - containerInfo.startTime.getTime()) / 1000)
          : null,
      });
    } catch (error) {
      statusCache.set(service.id, {
        status: "failed",
        message: error.message,
        timestamp: now,
        cpu: 0,
        startTime: null,
        uptime: null,
      });
    }
  }
  isRunningChecks = false;
}

// Get CPU usage for all containers in one call
async function getAllCpuUsage() {
  try {
    const output = execSync(
      `docker stats --no-stream --format "table {{.Name}}\t{{.CPUPerc}}"`,
      {
        encoding: "utf8",
        timeout: 10000,
      }
    );

    const cpuData = {};
    const lines = output.split("\n").slice(1); // Skip header

    for (const line of lines) {
      if (line.trim()) {
        const [name, cpu] = line.split("\t");
        if (name && (name.startsWith("freegle-") || name.startsWith("modtools-")) && cpu) {
          cpuData[name] = parseFloat(cpu.replace("%", "")) || 0;
        }
      }
    }

    return cpuData;
  } catch (error) {
    console.error("Error getting CPU stats:", error);
    return {};
  }
}

// Function to run Playwright tests in Docker
async function runPlaywrightTests(testFile = null, testName = null) {
  if (testStatus.status === "running") {
    throw new Error("Tests are already running");
  }

  let testDesc = "";
  if (testFile) testDesc += ` for file: ${testFile}`;
  if (testName) testDesc += ` with grep: "${testName}"`;
  if (!testFile && !testName) testDesc = " (all tests)";

  let statusMessage = "Initializing test environment...";
  if (testFile && testName) {
    statusMessage = `Running test "${testName}" in ${testFile}`;
  } else if (testFile) {
    statusMessage = `Running specific test file: ${testFile}`;
  } else if (testName) {
    statusMessage = `Running tests matching: "${testName}"`;
  }

  testStatus = {
    status: "running",
    message: statusMessage,
    logs: "",
    success: false,
    startTime: new Date(),
    endTime: null,
    completedTests: 0,
    totalTests: 0, // Reset to 0 - will be determined dynamically by the progress tracker
    testFile: testFile, // Store the test file for reference
    testName: testName, // Store the test name filter for reference
  };

  console.log("Starting Playwright tests in Docker" + testDesc);

  try {
    // Clear any stale progress files from previous test runs
    try {
      const { execSync } = require("child_process");
      execSync(
        'docker exec freegle-playwright sh -c "rm -f /app/test-results/test-progress.json /app/playwright-results.json"',
        { timeout: 5000 }
      );
      console.log("Cleared stale progress files");
    } catch (error) {
      console.warn("Could not clear progress files:", error.message);
    }

    // First, ensure the freegle container is running and accessible
    testStatus.message = "Checking Freegle container status...";

    // Check that the required containers are running
    const freegleProdCheck = execSync(
      'docker ps --filter "name=freegle-prod-local" --format "{{.Status}}"',
      {
        encoding: "utf8",
        timeout: 5000,
      }
    ).trim();

    if (!freegleProdCheck.includes("Up")) {
      throw new Error(
        "Freegle Production container is not running. Tests require the production site to be accessible."
      );
    }

    // Restart Playwright container to kill any existing processes
    testStatus.message = "Restarting Playwright container...";
    testStatus.logs += "Freegle Production container is running\n";
    testStatus.logs +=
      "Restarting Playwright container to kill existing processes\n";

    try {
      execSync("docker restart freegle-playwright", {
        encoding: "utf8",
        timeout: 30000,
      });
      testStatus.logs += "Playwright container restarted\n";
    } catch (restartError) {
      console.warn(
        "Failed to restart Playwright container:",
        restartError.message
      );
      testStatus.logs += `Warning: Failed to restart container: ${restartError.message}\n`;
    }

    // Set up test environment (FreeglePlayground group, test users) - same as PHPUnit tests
    testStatus.message = "Setting up test environment...";
    testStatus.logs += "Setting up test environment (FreeglePlayground group, test users)...\n";

    try {
      // Use freegle-apiv1 (not phpunit container) since Playwright tests run against the main iznik database
      execSync(
        'docker exec freegle-apiv1 sh -c "cd /var/www/iznik && php install/testenv.php"',
        {
          encoding: "utf8",
          timeout: 60000,
        }
      );
      testStatus.logs += "Test environment set up successfully\n";
    } catch (setupError) {
      console.warn("Test database setup warning:", setupError.message);
      testStatus.logs += `Warning: Test database setup issue: ${setupError.message}\n`;
      // Continue anyway - the database might already be set up
    }

    // Verify Traefik routes are working before running tests
    // This ensures Traefik has discovered and can route to all backends
    // Run from Playwright container which has host network access to .localhost domains
    testStatus.message = "Verifying Traefik routes...";
    testStatus.logs += "Verifying Traefik routes are accessible...\n";

    // Routes to verify before tests - critical routes must pass or tests fail early
    const routesToVerify = [
      { name: 'freegle-prod', url: 'http://freegle-prod-local.localhost/', critical: true },
      { name: 'apiv1', url: 'http://apiv1.localhost/api/config', critical: true },
      { name: 'apiv2', url: 'http://apiv2.localhost:8192/api/group?id=1', critical: false },
      { name: 'delivery', url: 'http://delivery.localhost/?url=http://freegle-prod-local.localhost/icon.png&w=16&output=png', critical: false },
    ];

    const failedCriticalRoutes = [];

    for (const route of routesToVerify) {
      let routeVerified = false;
      // More attempts for critical routes - Traefik may need time to discover backends
      const maxAttempts = route.critical ? 15 : 5;
      const retryDelay = 2000;

      for (let attempt = 1; attempt <= maxAttempts && !routeVerified; attempt++) {
        try {
          // Run curl from Playwright container which has host network access
          const curlResult = execSync(
            `docker exec freegle-playwright curl -s -o /dev/null -w "%{http_code}" --max-time 10 "${route.url}"`,
            { encoding: 'utf8', timeout: 15000 }
          ).trim();

          const statusCode = parseInt(curlResult, 10);
          if (statusCode >= 200 && statusCode < 500) {
            testStatus.logs += `✓ ${route.name} route verified (HTTP ${statusCode})\n`;
            routeVerified = true;
          } else {
            testStatus.logs += `  ${route.name} attempt ${attempt}/${maxAttempts}: HTTP ${statusCode}\n`;
          }
        } catch (curlError) {
          testStatus.logs += `  ${route.name} attempt ${attempt}/${maxAttempts}: ${curlError.message}\n`;
        }

        if (!routeVerified && attempt < maxAttempts) {
          await new Promise(resolve => setTimeout(resolve, retryDelay));
        }
      }

      if (!routeVerified) {
        if (route.critical) {
          testStatus.logs += `✗ CRITICAL: ${route.name} route not verified after ${maxAttempts} attempts\n`;
          failedCriticalRoutes.push(route.name);
        } else {
          testStatus.logs += `⚠ Warning: ${route.name} route not verified after ${maxAttempts} attempts\n`;
        }
      }
    }

    // Fail early if critical routes aren't available - tests will definitely fail
    if (failedCriticalRoutes.length > 0) {
      const errorMsg = `Critical routes not available: ${failedCriticalRoutes.join(', ')}. Tests cannot proceed.`;
      testStatus.status = "failed";
      testStatus.message = errorMsg;
      testStatus.logs += `\n${errorMsg}\n`;
      testStatus.logs += "This usually indicates a Traefik routing issue. Check that apiv1 container started before Traefik.\n";
      testStatus.endTime = new Date();
      return;
    }

    testStatus.logs += "Route verification complete\n";

    testStatus.message = "Executing tests in Playwright container...";
    testStatus.logs += "Playwright container is ready\n";

    // Execute tests in the Playwright container using docker exec (without nohup for proper output capture)
    // Build playwright args: file path and/or grep filter
    let playwrightArgs = "";
    if (testFile) {
      playwrightArgs += `tests/e2e/${testFile}`;
    }
    if (testName) {
      // Escape quotes in testName for shell safety
      const escapedTestName = testName.replace(/"/g, '\\"');
      playwrightArgs += ` -g "${escapedTestName}"`;
    }
    // Enable coverage reporter for CI builds
    // Set NODE_PATH to find globally installed @playwright/test module
    const testCommand = `docker exec freegle-playwright sh -c "
      cd /app &&
      export ENABLE_MONOCART_REPORTER=true &&
      export NODE_PATH=/usr/lib/node_modules &&
      npx playwright test ${playwrightArgs}
    "`;

    console.log("Executing Playwright tests in container...");

    // Execute the command and capture output in real-time
    const { spawn } = require("child_process");
    const testProcess = spawn("sh", ["-c", testCommand], {
      stdio: ["pipe", "pipe", "pipe"],
    });

    let testOutput = "";
    let stdoutBuffer = "";
    let stderrBuffer = "";

    function processCompleteLines(buffer, isStderr = false) {
      const lines = buffer.split("\n");
      // Keep the last incomplete line in the buffer
      const incompleteLineIndex = buffer.endsWith("\n")
        ? lines.length
        : lines.length - 1;
      const completeLines = lines.slice(0, incompleteLineIndex);
      const remainingBuffer = buffer.endsWith("\n")
        ? ""
        : lines[incompleteLineIndex] || "";

      // Process each complete line
      completeLines.forEach((line) => {
        if (line.trim()) {
          updateProgressFromLine(line.trim(), isStderr);
        }
      });

      return remainingBuffer;
    }

    function updateProgressFromLine(line, isStderr = false) {
      const lowerLine = line.toLowerCase();

      // Extract total test count from initial Playwright output
      if (testStatus.totalTests === 0) {
        // Look for patterns like "Running 40 tests using 3 workers" or "Running 5 tests using 1 worker"
        const testCountMatch = line.match(/running (\d+) tests?\s+using/i);
        if (testCountMatch) {
          testStatus.totalTests = parseInt(testCountMatch[1]);
          console.log(
            `Total tests detected from "Running X tests": ${testStatus.totalTests}`
          );
        }

        // Also look for patterns like "[1/5]" which indicates test number out of total
        const testProgressMatch = line.match(/\[(\d+)\/(\d+)\]/);
        if (testProgressMatch) {
          const total = parseInt(testProgressMatch[2]);
          if (
            total > 0 &&
            (testStatus.totalTests === 0 || total < testStatus.totalTests)
          ) {
            testStatus.totalTests = total;
            console.log(
              `Total tests detected from progress marker: ${testStatus.totalTests}`
            );
          }
        }
      }

      // Update status based on line content with more detailed progress
      if (lowerLine.includes("checking test environment")) {
        testStatus.message = "Initializing test environment...";
      } else if (
        lowerLine.includes("installing alpine") ||
        lowerLine.includes("apk add")
      ) {
        testStatus.message = "Installing system dependencies...";
      } else if (lowerLine.includes("installing playwright")) {
        testStatus.message = "Installing Playwright browsers...";
      } else if (lowerLine.includes("running playwright")) {
        testStatus.message = "Running Playwright tests...";
      } else if (lowerLine.includes("running") && lowerLine.includes("tests")) {
        const testMatch = line.match(/running (\d+) tests?\s+using/i);
        if (testMatch && testStatus.totalTests === 0) {
          testStatus.totalTests = parseInt(testMatch[1]);
          console.log(`Total tests detected: ${testStatus.totalTests}`);
        }
        const currentTest = (testStatus.completedTests || 0) + 1;
        const total =
          testStatus.totalTests ||
          (testMatch ? parseInt(testMatch[1]) : "unknown");
        testStatus.message = `Running test ${currentTest}/${total}`;
      } else if (
        testStatus.totalTests > 0 &&
        lowerLine.includes("running") &&
        lowerLine.includes("test")
      ) {
        // Once we have total, show progress for any running test mentions
        const currentTest = (testStatus.completedTests || 0) + 1;
        testStatus.message = `Running test ${currentTest}/${testStatus.totalTests}`;
      } else if (line.includes("Closed browser context after test")) {
        // Use browser context closure to count completed tests - but don't count retries
        // Only count unique test completions, not retry attempts
        const logLines = testStatus.logs.split("\n");
        const passedTests = logLines.filter(
          (line) => line.includes("✓") || line.match(/\d+\s+passed/)
        ).length;
        const completed = Math.min(passedTests, testStatus.totalTests || 999);

        // Store test progress for progress bar (only if higher than current)
        if (completed >= (testStatus.completedTests || 0)) {
          testStatus.completedTests = completed;
        }

        // Also try to get pass/fail counts from test result markers if available
        const passed = (testStatus.logs.match(/^\s*✓\s+\d+/gm) || []).length;
        const failed = (testStatus.logs.match(/^\s*✘\s+\d+/gm) || []).length;

        testStatus.message = `${completed}/${
          testStatus.totalTests || "?"
        } tests completed (${passed}✓ ${failed}✘)`;
      } else if (line.match(/^\s*[✓✘]\s+\d+/)) {
        // Extract current test progress from individual test results as fallback
        // Count all test results in the entire log, not just the current line
        const allResults = testStatus.logs.match(/^\s*[✓✘]\s+\d+/gm) || [];
        if (allResults.length > 0) {
          const completed = allResults.length;
          const passed = (testStatus.logs.match(/^\s*✓\s+\d+/gm) || []).length;
          const failed = (testStatus.logs.match(/^\s*✘\s+\d+/gm) || []).length;
          const totalTests = testStatus.totalTests;

          // Store test progress for progress bar (only if higher than current)
          if (completed >= (testStatus.completedTests || 0)) {
            testStatus.completedTests = completed;
          }

          // Show the actual test result line for context
          const result = line.trim();
          testStatus.message = `${completed}/${totalTests} tests completed (${passed}✓ ${failed}✘) | ${result}`;
        }
      } else if (line.match(/^\s*\d+\)\s+.+\.spec\.js:\d+:\d+\s+›/)) {
        // Detect active test execution by looking for test file references
        const testMatch = line.match(
          /^\s*\d+\)\s+(.+\.spec\.js):\d+:\d+\s+›\s+(.+)$/
        );
        if (testMatch) {
          const testFile = testMatch[1].replace(/.*\//, ""); // Get just the filename
          const testName = testMatch[2];
          const currentTest = (testStatus.completedTests || 0) + 1;
          const totalTests = testStatus.totalTests;
          testStatus.message = `Running test ${currentTest}/${totalTests}: ${testName} (${testFile})`;
        }
      } else if (
        line.includes("› ") &&
        (line.includes("test-") || line.includes(".spec.js"))
      ) {
        // Generic test execution detection - show the actual line
        const cleanLine = line.replace(/^\s*\d+\)\s*/, "").trim();
        testStatus.message = `Running: ${cleanLine}`;
      } else if (
        lowerLine.includes("passed") &&
        lowerLine.includes("failed") &&
        line.match(/\d+\s+passed.*\d+\s+failed/i)
      ) {
        // Only show final results when we see a complete summary line
        const passedMatch = line.match(/(\d+)\s+passed/i);
        const failedMatch = line.match(/(\d+)\s+failed/i);
        const skippedMatch = line.match(/(\d+)\s+skipped/i);

        if (passedMatch || failedMatch) {
          const passed = passedMatch ? passedMatch[1] : "0";
          const failed = failedMatch ? failedMatch[1] : "0";
          const skipped = skippedMatch ? skippedMatch[1] : "0";
          testStatus.message = `Tests completed: ${passed} passed, ${failed} failed${
            skipped !== "0" ? ", " + skipped + " skipped" : ""
          }`;
        }
      } else if (
        lowerLine.includes("generating") &&
        (lowerLine.includes("html report") ||
          lowerLine.includes("playwright report"))
      ) {
        // Only show "generating reports" when we explicitly see HTML report generation
        testStatus.message = "Generating HTML test reports...";
      } else if (
        lowerLine.includes("generating") &&
        lowerLine.includes("coverage")
      ) {
        testStatus.message = "Generating code coverage reports...";
      } else if (!isStderr && line.length > 10) {
        // Show the most recent meaningful line for general progress
        const meaningfulLine =
          line.length > 80 ? line.substring(0, 80) + "..." : line;
        const currentMessage = testStatus.message || "Running tests...";
        const timestamp = new Date().toLocaleTimeString("en-US", {
          hour12: false,
        });

        // Only update if this looks like meaningful progress (not just whitespace or timestamps)
        if (line.match(/[a-zA-Z]/) && !line.match(/^\s*$/)) {
          // Check if it's a test action or navigation
          if (
            line.includes("page.") ||
            line.includes("test") ||
            line.includes("browser") ||
            line.includes("click") ||
            line.includes("fill") ||
            line.includes("wait") ||
            line.includes("navigation") ||
            line.includes("loading") ||
            line.includes("error")
          ) {
            testStatus.message = `${
              currentMessage.split(" | ")[0]
            } | ${meaningfulLine} (${timestamp})`;
            testStatus.lastOutputTime = Date.now();
          }
        }
      }
    }

    testProcess.stdout.on("data", (data) => {
      const output = data.toString();
      testOutput += output;
      testStatus.logs += output;

      // Add to buffer and process complete lines
      stdoutBuffer += output;
      stdoutBuffer = processCompleteLines(stdoutBuffer, false);

      console.log("Test stdout:", output);
    });

    testProcess.stderr.on("data", (data) => {
      const output = data.toString();
      testOutput += output;
      testStatus.logs += output;

      // Add to buffer and process complete lines
      stderrBuffer += output;
      stderrBuffer = processCompleteLines(stderrBuffer, true);

      console.log("Test stderr:", output);
    });

    // Periodically check test progress by reading the progress file
    const progressCheckInterval = setInterval(() => {
      if (testStatus.status === "running") {
        try {
          const { execSync } = require("child_process");
          const progressOutput = execSync(
            'docker exec freegle-playwright cat /app/test-results/test-progress.json 2>/dev/null || echo "{}"',
            { encoding: "utf8", timeout: 5000 }
          );
          const progress = JSON.parse(progressOutput.trim() || "{}");

          if (
            progress.totalTests !== undefined &&
            testStatus.totalTests === 0
          ) {
            // Update total tests if we haven't detected it yet
            testStatus.totalTests = progress.totalTests;
          }

          if (testStatus.totalTests > 0) {
            // Only count actually completed (passed) tests, NOT failed tests or retries
            const actuallyCompleted = progress.completedTests || 0;
            const currentTotal = testStatus.totalTests;

            // Find currently running test
            let currentRunningTest = null;
            if (progress.tests) {
              for (const [testId, test] of Object.entries(progress.tests)) {
                if (test.status === "running") {
                  currentRunningTest = {
                    title: test.title,
                    testId: testId,
                  };
                  break;
                }
              }
            }

            console.log(
              `Progress check: ${actuallyCompleted}/${currentTotal} tests completed (${
                progress.runningTests || 0
              } running, ${progress.failedTests || 0} failed)`
            );

            // Only update if the count is higher (never go backwards) or if we have a current running test
            if (actuallyCompleted >= (testStatus.completedTests || 0)) {
              testStatus.completedTests = actuallyCompleted;
            }
            testStatus.currentRunningTest = currentRunningTest;
            testStatus.message = `${testStatus.completedTests}/${currentTotal} tests completed`;

            if (progress.failedTests > 0) {
              testStatus.message += ` (${progress.failedTests} failed)`;
            }
          }
        } catch (error) {
          console.warn("Failed to read test progress file:", error.message);
        }
      }
    }, 5000); // Check every 5 seconds for more responsive updates

    testProcess.on("close", (code) => {
      clearInterval(progressCheckInterval);
      testStatus.endTime = new Date();
      const duration = Math.round(
        (testStatus.endTime - testStatus.startTime) / 1000
      );

      // Check for various failure conditions regardless of exit code
      if (testStatus.logs.includes("Error: No tests found")) {
        testStatus.status = "failed";
        testStatus.success = false;
        testStatus.message = `Tests failed: No tests found after ${duration}s`;
        testStatus.logs += "\n❌ Tests failed: No tests found";
      } else if (
        testStatus.logs.includes("Testing stopped early after") &&
        testStatus.logs.includes("maximum allowed failures")
      ) {
        // Extract failure information from logs
        const failedMatch = testStatus.logs.match(/(\d+)\s+failed/);
        const didNotRunMatch = testStatus.logs.match(/(\d+)\s+did not run/);
        const failedCount = failedMatch ? failedMatch[1] : "some";
        const skippedCount = didNotRunMatch ? didNotRunMatch[1] : "";

        testStatus.status = "failed";
        testStatus.success = false;
        testStatus.message = `Tests failed: ${failedCount} failed${
          skippedCount ? `, ${skippedCount} skipped` : ""
        } after ${duration}s`;
        testStatus.logs += `\n❌ Tests failed: ${failedCount} failed${
          skippedCount ? `, ${skippedCount} skipped` : ""
        }`;
      } else if (
        testStatus.logs.match(/\d+\s+failed/) &&
        !testStatus.logs.includes("0 failed")
      ) {
        // General test failure detection
        const failedMatch = testStatus.logs.match(/(\d+)\s+failed/);
        const failedCount = failedMatch ? failedMatch[1] : "some";

        testStatus.status = "failed";
        testStatus.success = false;
        testStatus.message = `Tests failed: ${failedCount} test${
          failedCount !== "1" ? "s" : ""
        } failed after ${duration}s`;
        testStatus.logs += `\n❌ Tests failed: ${failedCount} test${
          failedCount !== "1" ? "s" : ""
        } failed`;
      } else if (code === 0) {
        // Copy coverage files from mounted directory to expected location for CircleCI
        try {
          const copyCommands = [
            // Copy coverage directory if it exists in mounted location
            'docker exec freegle-playwright sh -c "if [ -d /host-playwright-config/coverage ] && [ "$(ls -A /host-playwright-config/coverage 2>/dev/null)" ]; then cp -r /host-playwright-config/coverage/* /app/coverage/; fi"',
            // Copy monocart-report if it exists in mounted location
            'docker exec freegle-playwright sh -c "if [ -d /host-playwright-config/monocart-report ] && [ "$(ls -A /host-playwright-config/monocart-report 2>/dev/null)" ]; then cp -r /host-playwright-config/monocart-report/* /app/monocart-report/; fi"',
          ];

          for (const command of copyCommands) {
            execSync(command, { encoding: "utf8", timeout: 10000 });
          }
          console.log(
            "✅ Copied coverage files from mounted directory to /app/"
          );
        } catch (copyError) {
          console.warn("⚠️ Failed to copy coverage files:", copyError.message);
        }

        // Check if coverage was generated (required in CI)
        let coverageGenerated = false;
        try {
          const coverageCheckCommand =
            'docker exec freegle-playwright sh -c "test -f /app/monocart-report/coverage/lcov.info && echo exists"';
          const coverageResult = execSync(coverageCheckCommand, {
            encoding: "utf8",
            timeout: 5000,
          }).trim();
          coverageGenerated = coverageResult === "exists";

          if (coverageGenerated) {
            console.log(
              "✅ Coverage file generated at monocart-report/coverage/lcov.info"
            );
            testStatus.logs += "\n✅ Coverage report generated successfully";
          } else {
            console.warn(
              "⚠️ Coverage file not found at monocart-report/coverage/lcov.info"
            );
          }
        } catch (coverageError) {
          console.warn("Failed to check coverage:", coverageError.message);
        }

        // In CI environment, fail if coverage wasn't generated
        const isCI =
          process.env.CI === "true" || process.env.CIRCLECI === "true";
        if (isCI && !coverageGenerated) {
          testStatus.status = "failed";
          testStatus.success = false;
          testStatus.message = `Tests passed but coverage generation failed after ${duration}s`;
          testStatus.logs +=
            "\n❌ Tests passed but required coverage report was not generated!";
          testStatus.logs += "\n⚠️ Ensure ENABLE_MONOCART_REPORTER=true is set";
        } else {
          testStatus.status = "completed";
          testStatus.success = true;
          testStatus.message = `Tests completed successfully in ${duration}s`;
          testStatus.logs += "\n✅ All tests passed!";
        }
      } else {
        testStatus.status = "failed";
        testStatus.success = false;
        testStatus.message = `Tests failed (exit code ${code}) after ${duration}s`;
        testStatus.logs += `\n❌ Tests failed with exit code ${code}`;
      }

      // Start report server automatically after test completion (both success and failure)
      try {
        console.log("Starting Playwright report server...");
        const reportServerCommand =
          'docker exec -d freegle-playwright sh -c "cd /app && nohup npx playwright show-report --host=0.0.0.0 --port=9323 > /tmp/report-server.log 2>&1 &"';
        execSync(reportServerCommand);
        console.log(
          "Playwright report server started successfully on port 9323"
        );
        testStatus.logs += "\n📊 HTML report server started on port 9323";
      } catch (reportError) {
        console.warn("Failed to start report server:", reportError.message);
        testStatus.logs += "\n⚠️ Warning: Failed to start report server";
      }

      console.log(`Test process finished with code ${code}`);
    });

    testProcess.on("error", (error) => {
      testStatus.status = "failed";
      testStatus.success = false;
      testStatus.message = `Test execution failed: ${error.message}`;
      testStatus.logs += `\nError: ${error.message}`;
      testStatus.endTime = new Date();
      console.error("Test process error:", error);
    });
  } catch (error) {
    testStatus.status = "failed";
    testStatus.success = false;
    testStatus.message = `Failed to start tests: ${error.message}`;
    testStatus.logs += `\nError: ${error.message}`;
    testStatus.endTime = new Date();
    console.error("Error starting tests:", error);
    throw error;
  }
}

// Start background checks after a delay and then every interval
setTimeout(() => {
  runBackgroundChecks();
  setInterval(runBackgroundChecks, CHECK_INTERVAL);
}, 2000);

const httpServer = http.createServer(async (req, res) => {
  // Enable CORS
  res.setHeader("Access-Control-Allow-Origin", "*");
  res.setHeader("Access-Control-Allow-Methods", "GET, POST, OPTIONS");
  res.setHeader("Access-Control-Allow-Headers", "Content-Type");

  if (req.method === "OPTIONS") {
    res.writeHead(200);
    res.end();
    return;
  }

  const parsedUrl = url.parse(req.url, true);

  // Container restart endpoint
  if (
    parsedUrl.pathname === "/api/container/restart" &&
    req.method === "POST"
  ) {
    let body = "";
    req.on("data", (chunk) => (body += chunk.toString()));
    req.on("end", async () => {
      try {
        const { container } = JSON.parse(body);

        if (!container || !/^(freegle|modtools)-[a-zA-Z0-9_-]+$/.test(container)) {
          res.writeHead(400, { "Content-Type": "text/plain" });
          res.end("Invalid container name");
          return;
        }

        console.log(`Restarting container: ${container}`);
        execSync(`docker restart ${container}`, { timeout: 30000 });

        res.writeHead(200, { "Content-Type": "text/plain" });
        res.end(`Container ${container} restarted successfully`);
      } catch (error) {
        console.error("Restart error:", error);
        res.writeHead(500, { "Content-Type": "text/plain" });
        res.end(`Failed to restart container: ${error.message}`);
      }
    });
    return;
  }

  // Start live container endpoint (for freegle-dev-live which uses a profile)
  if (
    parsedUrl.pathname === "/api/container/start-live" &&
    req.method === "POST"
  ) {
    try {
      console.log("Starting freegle-dev-live container with dev-live profile...");
      execSync(
        `${DOCKER_COMPOSE} --profile dev-live up -d freegle-dev-live`,
        {
          timeout: 120000,
          cwd: "/project",
        }
      );

      res.writeHead(200, { "Content-Type": "application/json" });
      res.end(JSON.stringify({ success: true, message: "Container starting" }));
    } catch (error) {
      console.error("Start live container error:", error);
      res.writeHead(500, { "Content-Type": "application/json" });
      res.end(JSON.stringify({ success: false, error: error.message }));
    }
    return;
  }

  // Start modtools live container endpoint (for modtools-dev-live which uses a profile)
  if (
    parsedUrl.pathname === "/api/container/start-modtools-live" &&
    req.method === "POST"
  ) {
    try {
      console.log("Starting modtools-dev-live container with dev-live profile...");
      execSync(
        `${DOCKER_COMPOSE} --profile dev-live up -d modtools-dev-live`,
        {
          timeout: 120000,
          cwd: "/project",
        }
      );

      res.writeHead(200, { "Content-Type": "application/json" });
      res.end(JSON.stringify({ success: true, message: "Container starting" }));
    } catch (error) {
      console.error("Start modtools live container error:", error);
      res.writeHead(500, { "Content-Type": "application/json" });
      res.end(JSON.stringify({ success: false, error: error.message }));
    }
    return;
  }

  // Container rebuild endpoint
  if (
    parsedUrl.pathname === "/api/container/rebuild" &&
    req.method === "POST"
  ) {
    let body = "";
    req.on("data", (chunk) => (body += chunk.toString()));
    req.on("end", async () => {
      try {
        const { container, service } = JSON.parse(body);

        if (!container || !/^(freegle|modtools)-[a-zA-Z0-9_-]+$/.test(container)) {
          res.writeHead(400, { "Content-Type": "text/plain" });
          res.end("Invalid container name");
          return;
        }

        if (!service || !/^[a-zA-Z0-9_-]+$/.test(service)) {
          res.writeHead(400, { "Content-Type": "text/plain" });
          res.end("Invalid service name");
          return;
        }

        console.log(`Rebuilding service: ${service} (${container})`);

        // Write rebuild request to shared volume for host to process
        const rebuildRequest = {
          service: service,
          container: container,
          timestamp: Date.now(),
          id: Math.random().toString(36).substr(2, 9),
        };

        const fs = require("fs");
        const requestFile = `/rebuild-requests/rebuild-${rebuildRequest.id}.json`;
        fs.writeFileSync(requestFile, JSON.stringify(rebuildRequest));

        // Wait for completion or timeout
        let completed = false;
        let attempts = 0;
        const maxAttempts = 60; // 5 minutes max

        while (!completed && attempts < maxAttempts) {
          await new Promise((resolve) => setTimeout(resolve, 5000)); // Wait 5 seconds
          attempts++;

          try {
            // Check if request file was processed (removed by host script)
            if (!fs.existsSync(requestFile)) {
              completed = true;
              break;
            }
          } catch (e) {
            // File might be being processed
          }
        }

        if (!completed) {
          // Clean up and timeout
          try {
            fs.unlinkSync(requestFile);
          } catch (e) {}
          throw new Error("Rebuild request timed out");
        }

        res.writeHead(200, { "Content-Type": "text/plain" });
        res.end(`Service ${service} rebuilt and restarted successfully`);
      } catch (error) {
        console.error("Rebuild error:", error);
        res.writeHead(500, { "Content-Type": "text/plain" });
        res.end(`Failed to rebuild service: ${error.message}`);
      }
    });
    return;
  }

  // Recreate test users endpoint
  if (
    parsedUrl.pathname === "/api/recreate-test-users" &&
    req.method === "POST"
  ) {
    try {
      const results = [];

      // Delete existing test users first to ensure clean recreate
      try {
        execSync(
          "docker exec freegle-percona mysql -u root -piznik iznik -e \"DELETE FROM users WHERE id IN (SELECT userid FROM (SELECT userid FROM users_emails WHERE email IN ('test@test.com', 'testmod@test.com')) AS subquery)\"",
          { encoding: "utf8", timeout: 10000 }
        );
        results.push("Deleted existing test users");
      } catch (error) {
        results.push(
          `Warning: Failed to delete existing users - ${error.message}`
        );
      }

      // Recreate test@test.com
      try {
        const testUserResult = execSync(
          'docker exec freegle-apiv1 php /var/www/iznik/scripts/cli/user_create.php -e test@test.com -n "Test User" -p freegle',
          { encoding: "utf8", timeout: 30000 }
        );
        results.push(`test@test.com: ${testUserResult.trim()}`);
      } catch (error) {
        results.push(`test@test.com: Failed - ${error.message}`);
      }

      // Recreate testmod@test.com
      try {
        const modUserResult = execSync(
          'docker exec freegle-apiv1 php /var/www/iznik/scripts/cli/user_create.php -e testmod@test.com -n "Test Mod" -p freegle',
          { encoding: "utf8", timeout: 30000 }
        );
        results.push(`testmod@test.com: ${modUserResult.trim()}`);
      } catch (error) {
        results.push(`testmod@test.com: Failed - ${error.message}`);
      }

      res.writeHead(200, { "Content-Type": "application/json" });
      res.end(
        JSON.stringify({
          success: true,
          message: "Users recreated successfully",
          details: results,
        })
      );
    } catch (error) {
      res.writeHead(500, { "Content-Type": "application/json" });
      res.end(
        JSON.stringify({
          success: false,
          error: `Failed to recreate users: ${error.message}`,
        })
      );
    }
    return;
  }

  // Playwright test execution endpoint
  if (parsedUrl.pathname === "/api/tests/playwright" && req.method === "POST") {
    let body = "";

    req.on("data", (chunk) => {
      body += chunk.toString();
    });

    req.on("end", () => {
      try {
        let testFile = null;
        let testName = null;

        // First check query parameters (parsedUrl.query is an object from url.parse)
        if (parsedUrl.query && parsedUrl.query.testSpec) {
          testFile = parsedUrl.query.testSpec;
        }
        // Support both 'testName' and 'filter' as query parameters
        if (parsedUrl.query && (parsedUrl.query.testName || parsedUrl.query.filter)) {
          testName = parsedUrl.query.testName || parsedUrl.query.filter;
        }

        // Parse request body if it exists (body takes precedence)
        if (body.trim()) {
          try {
            const requestData = JSON.parse(body);
            if (requestData.testFile) testFile = requestData.testFile;
            // Support both 'testName' and 'filter' in request body
            if (requestData.testName || requestData.filter) {
              testName = requestData.testName || requestData.filter;
            }
          } catch (parseError) {
            console.warn("Failed to parse request body:", parseError.message);
          }
        }

        let logMessage = "Received request to run Playwright tests";
        if (testFile) logMessage += ` for file: ${testFile}`;
        if (testName) logMessage += ` with grep: "${testName}"`;
        if (!testFile && !testName) logMessage += " (all tests)";
        console.log(logMessage);

        // Start tests asynchronously (dependencies are handled by Docker Compose)
        runPlaywrightTests(testFile, testName).catch((error) => {
          console.error("Test execution error:", error);
        });

        res.writeHead(200, { "Content-Type": "text/plain" });
        res.end("Playwright tests started successfully");
      } catch (error) {
        console.error("Failed to start tests:", error);
        res.writeHead(500, { "Content-Type": "text/plain" });
        res.end(`Failed to start tests: ${error.message}`);
      }
    });
    return;
  }

  // Playwright test status endpoint
  if (
    parsedUrl.pathname === "/api/tests/playwright/status" &&
    req.method === "GET"
  ) {
    res.writeHead(200, { "Content-Type": "application/json" });
    const now = Date.now();
    const timeSinceLastOutput = testStatus.lastOutputTime
      ? Math.floor((now - testStatus.lastOutputTime) / 1000)
      : null;

    res.end(
      JSON.stringify({
        status: testStatus.status,
        message: testStatus.message,
        logs:
          testStatus.logs.length > 5000
            ? "...(truncated)\n" + testStatus.logs.slice(-5000)
            : testStatus.logs,
        success: testStatus.success,
        startTime: testStatus.startTime,
        endTime: testStatus.endTime,
        completedTests: testStatus.completedTests,
        totalTests: testStatus.totalTests,
        currentRunningTest: testStatus.currentRunningTest,
        lastOutputTime: testStatus.lastOutputTime,
        timeSinceLastOutput: timeSinceLastOutput,
      })
    );
    return;
  }

  // Playwright report redirect endpoint - redirect to container's built-in server
  if (
    parsedUrl.pathname === "/api/tests/playwright/report" &&
    req.method === "GET"
  ) {
    res.writeHead(302, {
      Location: "http://localhost:9323",
    });
    res.end();
    return;
  }

  // Go tests status endpoint
  if (parsedUrl.pathname === "/api/tests/go/status" && req.method === "GET") {
    res.writeHead(200, { "Content-Type": "application/json" });
    res.end(JSON.stringify(testStatuses.goTests || { status: "idle" }));
    return;
  }

  // Go tests endpoint
  if (parsedUrl.pathname === "/api/tests/go" && req.method === "POST") {
    console.log("Starting Go tests...");

    // Check for coverage parameter (for CI)
    const withCoverage = parsedUrl.query && parsedUrl.query.coverage === "true";

    // Check if already running
    if (testStatuses.goTests && testStatuses.goTests.status === "running") {
      res.writeHead(409, { "Content-Type": "application/json" });
      res.end(JSON.stringify({ error: "Go tests are already running" }));
      return;
    }

    // Initialize test status
    testStatuses.goTests = {
      status: "running",
      message: "Setting up Go test database...",
      logs: "",
      progress: { completed: 0, total: 0, passed: 0, failed: 0, current: "" },
      startTime: Date.now(),
      withCoverage,
    };

    res.writeHead(200, { "Content-Type": "application/json" });
    res.end(JSON.stringify({ status: "started" }));

    // Build test command - add race detection and coverage for CI
    const testCmd = withCoverage
      ? "export CGO_ENABLED=1 && export MYSQL_DBNAME=iznik_go_test && go test -v -race -coverprofile=coverage.out ./test/... -coverpkg ./..."
      : "export MYSQL_DBNAME=iznik_go_test && go test ./test/... -v";

    // Run tests asynchronously
    const { spawn } = require("child_process");
    const testProcess = spawn(
      "sh",
      [
        "-c",
        `
        set -e
        echo "Setting up Go test database (iznik_go_test)..."

        # Copy schema from main iznik database (which batch container has already migrated)
        # This ensures Go tests have the exact same schema as production
        docker exec freegle-apiv1 sh -c "\\
          mysql -h percona -u root -piznik -e 'DROP DATABASE IF EXISTS iznik_go_test; CREATE DATABASE iznik_go_test;' && \\
          mysqldump -h percona -u root -piznik --no-data --routines --triggers iznik | mysql -h percona -u root -piznik iznik_go_test && \\
          mysql -h percona -u root -piznik -e \\"SET GLOBAL sql_mode = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'\\" && \\
          mysql -h percona -u root -piznik -e \\"SET GLOBAL sql_mode=(SELECT REPLACE(@@sql_mode,'ONLY_FULL_GROUP_BY',''));\\"" || echo "Warning: Database setup had issues, continuing..."

        echo "Running Go tests against iznik_go_test database..."
        docker exec -w /app freegle-apiv2 sh -c "${testCmd} 2>&1"
      `,
      ],
      { stdio: "pipe" }
    );

    testProcess.stdout.on("data", (data) => {
      const text = data.toString();
      testStatuses.goTests.logs += text;

      // Parse Go test output for progress
      const lines = text.split("\n");
      for (const line of lines) {
        // Count test starts: === RUN   TestName
        if (line.match(/^=== RUN\s+(\S+)/)) {
          const match = line.match(/^=== RUN\s+(\S+)/);
          testStatuses.goTests.progress.current = match[1];
        }
        // Count passes: --- PASS: TestName
        if (line.match(/^--- PASS:/)) {
          testStatuses.goTests.progress.passed++;
          testStatuses.goTests.progress.completed++;
        }
        // Count failures: --- FAIL: TestName
        if (line.match(/^--- FAIL:/)) {
          testStatuses.goTests.progress.failed++;
          testStatuses.goTests.progress.completed++;
        }
        // Get total from "ok" or "FAIL" summary lines
        if (line.match(/^(ok|FAIL)\s+\S+/)) {
          testStatuses.goTests.message = `Running tests... ${testStatuses.goTests.progress.passed}✓ ${testStatuses.goTests.progress.failed}✗`;
        }
      }

      // Update message with current progress
      const p = testStatuses.goTests.progress;
      if (p.current) {
        testStatuses.goTests.message = `Running: ${p.current} (${p.passed}✓ ${p.failed}✗)`;
      }
    });

    testProcess.stderr.on("data", (data) => {
      testStatuses.goTests.logs += data.toString();
    });

    testProcess.on("close", (code) => {
      const p = testStatuses.goTests.progress;
      testStatuses.goTests.status = code === 0 ? "completed" : "failed";
      testStatuses.goTests.success = code === 0;
      testStatuses.goTests.endTime = Date.now();
      testStatuses.goTests.message = code === 0
        ? `All tests passed (${p.passed}✓)`
        : `Tests failed (${p.passed}✓ ${p.failed}✗)`;
      console.log(`Go tests completed with code ${code}`);
    });

    testProcess.on("error", (error) => {
      testStatuses.goTests.status = "failed";
      testStatuses.goTests.message = `Error: ${error.message}`;
      testStatuses.goTests.endTime = Date.now();
    });

    return;
  }

  // Laravel tests status endpoint
  if (parsedUrl.pathname === "/api/tests/laravel/status" && req.method === "GET") {
    res.writeHead(200, { "Content-Type": "application/json" });
    res.end(JSON.stringify(testStatuses.laravelTests || { status: "idle" }));
    return;
  }

  // Laravel tests endpoint
  if (parsedUrl.pathname === "/api/tests/laravel" && req.method === "POST") {
    console.log("Starting Laravel tests...");

    // Parse request body for filter parameter
    let body = "";
    req.on("data", (chunk) => {
      body += chunk.toString();
    });

    req.on("end", () => {
      let filter = "";
      if (body) {
        try {
          const data = JSON.parse(body);
          if (data.filter) {
            filter = ` --filter "${data.filter}"`;
            console.log(`Running Laravel tests with filter: ${data.filter}`);
          }
        } catch (e) {
          console.log("Error parsing request body:", e);
        }
      }

      // Check if already running
      if (testStatuses.laravelTests && testStatuses.laravelTests.status === "running") {
        res.writeHead(409, { "Content-Type": "application/json" });
        res.end(JSON.stringify({ error: "Laravel tests are already running" }));
        return;
      }

      // Initialize test status
      testStatuses.laravelTests = {
        status: "running",
        message: filter ? `Starting Laravel test (filtered)...` : "Starting Laravel tests...",
        logs: "",
        progress: { completed: 0, total: 0, passed: 0, failed: 0, current: "" },
        startTime: Date.now(),
      };

      res.writeHead(200, { "Content-Type": "application/json" });
      res.end(JSON.stringify({ status: "started", filter: filter ? filter.trim() : undefined }));

      // Build the phpunit command - skip coverage when filtering for speed
      const coverageFlag = filter ? "" : " --coverage-clover=/tmp/laravel-coverage.xml";
      const phpunitCmd = `docker exec -e VIA_STATUS_CONTAINER=1 freegle-batch vendor/bin/phpunit --testsuite=Unit,Feature${filter}${coverageFlag} 2>&1`;

      // Run tests asynchronously
      const { spawn } = require("child_process");
      const testProcess = spawn(
        "sh",
        [
          "-c",
          `
          set -e
          echo "Setting up Laravel test environment..."

          # Stop supervisor workers before running tests.
          # In CI, supervisor isn't running (CI=true skips it in entrypoint.sh).
          # In local dev, this stops background workers to prevent interference.
          echo "Stopping supervisor workers..."
          docker exec freegle-batch supervisorctl stop all 2>&1 || true

          # Set up fresh test database
          echo "Setting up fresh test database..."
          docker exec freegle-batch mysql -h percona -u root -piznik --skip-ssl -e "CREATE DATABASE IF NOT EXISTS iznik_batch_test" 2>&1
          docker exec -e DB_DATABASE=iznik_batch_test freegle-batch php artisan migrate:fresh --database=mysql --force 2>&1

          echo "Running Laravel tests${filter ? ' (filtered)' : ' with coverage'}..."
          ${phpunitCmd}
        `,
        ],
        { stdio: "pipe" }
      );

    testProcess.stdout.on("data", (data) => {
      const text = data.toString();
      testStatuses.laravelTests.logs += text;

      // Parse paratest/PHPUnit output for progress
      const lines = text.split("\n");
      for (const line of lines) {
        // Look for paratest progress format: "63 / 801 (  7%)" - this is the primary progress indicator
        const paratestMatch = line.match(/(\d+)\s*\/\s*(\d+)\s*\(/);
        if (paratestMatch) {
          const completed = parseInt(paratestMatch[1]);
          const total = parseInt(paratestMatch[2]);
          if (total > 0) {
            testStatuses.laravelTests.progress.completed = completed;
            testStatuses.laravelTests.progress.total = total;
            // Paratest doesn't distinguish pass/fail in progress line, assume all passed until we see failures
            testStatuses.laravelTests.progress.passed = completed - testStatuses.laravelTests.progress.failed;
          }
        }

        // Look for test count in final PHPUnit output
        const countMatch = line.match(/(\d+)\s+tests?,\s+(\d+)\s+assertions?/);
        if (countMatch) {
          testStatuses.laravelTests.progress.total = parseInt(countMatch[1]);
        }

        // Count F for failures in test output (paratest shows F for each failed test)
        const failMatches = line.match(/\bF\b/g);
        if (failMatches && !line.includes("Feature") && !line.includes("FAIL")) {
          // Only count standalone F characters, not F in words like "Feature" or "FAIL"
          const lineFailCount = (line.match(/^[.F]+$/g) || []).join('').match(/F/g);
          if (lineFailCount) {
            testStatuses.laravelTests.progress.failed += lineFailCount.length;
          }
        }

        // Look for "OK" or "FAILURES" in final output
        if (line.includes("OK (")) {
          const okMatch = line.match(/OK \((\d+) tests?/);
          if (okMatch) {
            testStatuses.laravelTests.progress.passed = parseInt(okMatch[1]);
            testStatuses.laravelTests.progress.completed = parseInt(okMatch[1]);
            testStatuses.laravelTests.progress.failed = 0;
          }
        }
        if (line.includes("FAILURES!")) {
          const failMatch = line.match(/Failures:\s*(\d+)/);
          if (failMatch) {
            testStatuses.laravelTests.progress.failed = parseInt(failMatch[1]);
          }
        }
      }

      // Update message with progress
      const p = testStatuses.laravelTests.progress;
      if (p.total > 0) {
        const percent = Math.round((p.completed / p.total) * 100);
        testStatuses.laravelTests.message = `Running tests... ${p.completed}/${p.total} (${percent}%) ${p.failed > 0 ? `- ${p.failed} failed` : ''}`;
      } else {
        testStatuses.laravelTests.message = `Running tests... ${p.passed}✓ ${p.failed}✗`;
      }
    });

    testProcess.stderr.on("data", (data) => {
      testStatuses.laravelTests.logs += data.toString();
    });

    testProcess.on("close", (code) => {
      const p = testStatuses.laravelTests.progress;
      testStatuses.laravelTests.status = code === 0 ? "completed" : "failed";
      testStatuses.laravelTests.success = code === 0;
      testStatuses.laravelTests.endTime = Date.now();
      const total = p.total > 0 ? p.total : p.completed;
      const passed = total - p.failed;
      testStatuses.laravelTests.message = code === 0
        ? `All ${total} tests passed ✓`
        : `Tests failed: ${passed}✓ ${p.failed}✗ of ${total}`;
      console.log(`Laravel tests completed with code ${code}`);

      // Collect the inotify monitoring log for artifacts
      try {
        const inotifyLog = execSync(
          "docker exec freegle-batch cat /tmp/bootstrap-cache-monitor.log 2>/dev/null || echo 'No inotify events recorded'",
          { encoding: "utf8", timeout: 10000 }
        );
        const inotifyArtifactPath = "/tmp/laravel-bootstrap-cache-monitor.log";
        fs.writeFileSync(inotifyArtifactPath,
          `=== Bootstrap Cache inotify Monitor Log ===\n` +
          `Time: ${new Date().toISOString()}\n` +
          `Test Exit Code: ${code}\n\n` +
          inotifyLog
        );
        console.log(`Saved inotify log to ${inotifyArtifactPath}`);

        // Also append to test logs for visibility
        if (inotifyLog && inotifyLog.trim() !== 'No inotify events recorded') {
          testStatuses.laravelTests.logs += `\n\n=== Bootstrap Cache inotify Events ===\n${inotifyLog}`;
        }
      } catch (e) {
        console.log("Warning: Failed to collect inotify log:", e.message);
      }

      // Restart supervisor workers that were stopped before tests
      try {
        execSync("docker exec freegle-batch supervisorctl start all 2>&1 || true", {
          encoding: "utf8",
          timeout: 30000,
        });
        console.log("Restarted supervisor workers after Laravel tests");
      } catch (e) {
        console.log("Warning: Failed to restart supervisor workers:", e.message);
      }
    });

    testProcess.on("error", (error) => {
      testStatuses.laravelTests.status = "failed";
      testStatuses.laravelTests.message = `Error: ${error.message}`;
      testStatuses.laravelTests.endTime = Date.now();
    });

    }); // end req.on("end")

    return;
  }

  // PHP tests endpoint
  if (parsedUrl.pathname === "/api/tests/php" && req.method === "POST") {
    console.log("Starting PHP tests...");

    // Parse request body for filter parameter
    let body = "";
    req.on("data", (chunk) => {
      body += chunk.toString();
    });

    req.on("end", () => {
      let filter = "";
      if (body) {
        try {
          const data = JSON.parse(body);
          if (data.filter) {
            filter = `--filter "${data.filter}"`;
            console.log(`Running PHP tests with filter: ${data.filter}`);
          }
        } catch (e) {
          console.log("Error parsing request body:", e);
        }
      }

      // Check if tests are already running
      if (testStatuses.phpTests && testStatuses.phpTests.status === "running") {
        res.writeHead(409, { "Content-Type": "text/plain" });
        res.end("PHP tests are already running");
        return;
      }

      // Initialize test status
      testStatuses.phpTests = {
        status: "running",
        message: "Setting up test environment...",
        logs: "",
        startTime: Date.now(),
        lastLine: "",
        progress: {
          total: 0,
          completed: 0,
          failed: 0,
          current: null,
          teamCityMode: false,
        },
      };

      const testStatus = testStatuses.phpTests;

      try {
        res.writeHead(200, { "Content-Type": "text/plain" });
        res.end("PHP tests started successfully");

        // Start and wait for the apiv1-phpunit container to be healthy
        testStatus.message = "Starting PHPUnit test container...";
        testStatus.logs += "Starting freegle-apiv1-phpunit container...\n";

        try {
          // Start the container if it's stopped (container must be created by docker-compose first)
          // We use 'docker start' because docker-compose from inside the container has path issues
          execSync("docker start freegle-apiv1-phpunit", {
            encoding: "utf8",
            timeout: 60000,
          });
          testStatus.logs += "Container started, waiting for health check...\n";

          // Wait for container to be healthy (up to 5 minutes)
          // Docker healthcheck has start_period: 120s + retries, so we need to wait longer
          const maxWait = 300000;
          const containerStartTime = Date.now();
          let healthy = false;

          while (Date.now() - containerStartTime < maxWait) {
            try {
              const health = execSync(
                'docker inspect --format="{{.State.Health.Status}}" freegle-apiv1-phpunit 2>/dev/null || echo "unknown"',
                { encoding: "utf8" }
              ).trim();

              if (health === "healthy") {
                healthy = true;
                break;
              }

              testStatus.message = `Waiting for container (${health})...`;
              testStatus.logs += `Container health: ${health}\n`;

              // Wait 5 seconds before checking again
              execSync("sleep 5");
            } catch (err) {
              // Container might not exist yet
              execSync("sleep 5");
            }
          }

          if (!healthy) {
            throw new Error("Container failed to become healthy within 5 minutes");
          }

          testStatus.logs += "Container is healthy!\n";
        } catch (startError) {
          console.error("Failed to start PHPUnit container:", startError.message);
          testStatus.status = "failed";
          testStatus.message = `Failed to start container: ${startError.message}`;
          testStatus.logs += `ERROR: ${startError.message}\n`;
          testStatus.endTime = Date.now();
          return;
        }

        // Set up test environment
        testStatus.message = "Setting up test environment...";
        testStatus.logs += "Setting up test environment...\n";

        try {
          execSync(
            'docker exec freegle-apiv1-phpunit sh -c "cd /var/www/iznik && php install/testenv.php"',
            {
              encoding: "utf8",
              timeout: 60000,
            }
          );
          testStatus.logs += "Test environment set up (FreeglePlayground group, test users)\n";
        } catch (setupError) {
          console.warn("Test database setup warning:", setupError.message);
          testStatus.logs += `Warning: Test database setup issue: ${setupError.message}\n`;
          // Continue anyway - the database might already be set up
        }

        // Run PHPUnit tests and write output to file
        const { spawn } = require("child_process");
        const outputFile = "/tmp/phpunit-output.log";

        // First, clear the output file
        execSync(
          `docker exec freegle-apiv1-phpunit sh -c "rm -f ${outputFile} && touch ${outputFile}"`
        );

        // Don't try to pre-count tests - TeamCity output will give us the correct count
        // including proper handling of data providers
        try {
          const testPath = filter || "/var/www/iznik/test/ut/php/";
          testStatus.message = "Starting PHPUnit tests...";
          testStatus.progress.total = 0; // Will be set from TeamCity output
          testStatus.progress.useMarkers = true;
          testStatus.progress.totalFromTeamCity = false;
        } catch (err) {
          // Ignore errors
          console.log("Could not get test count:", err.message);
        }

        const testProcess = spawn(
          "sh",
          [
            "-c",
            `
          docker exec -w /var/www/iznik freegle-apiv1-phpunit sh -c "
            echo 'Setting up test environment...' | tee ${outputFile} && \\
            php install/testenv.php 2>&1 | tee -a ${outputFile} || echo 'Warning: testenv.php failed but continuing...' | tee -a ${outputFile}; \\
            echo 'Running PHPUnit tests via wrapper script...' | tee -a ${outputFile} && \\
            echo 'Total tests: ${
              testStatus.progress.total
            }' | tee -a ${outputFile} && \\
            /var/www/iznik/run-phpunit.sh ${
              filter || "/var/www/iznik/test/ut/php/"
            } 2>&1 | tee -a ${outputFile}"
        `,
          ],
          {
            stdio: "pipe",
          }
        );
        let fullOutput = "";

        // Monitor the output file for progress
        const monitorInterval = setInterval(() => {
          try {
            // Count ALL test started markers from the entire file (not just last 10 lines)
            // This ensures we don't miss tests that run faster than our polling interval
            try {
              const markerCount = execSync(
                `docker exec freegle-apiv1-phpunit sh -c "grep -c '##PHPUNIT_TEST_STARTED##' ${outputFile} 2>/dev/null || echo '0'"`,
                { encoding: "utf8" }
              ).trim();
              const count = parseInt(markerCount) || 0;
              if (count > testStatus.progress.completed) {
                testStatus.progress.completed = count;
              }
            } catch (err) {
              // Ignore grep errors
            }

            // Get the last few lines for status message and other info
            const lastLines = execSync(
              `docker exec freegle-apiv1-phpunit sh -c "tail -n 10 ${outputFile} 2>/dev/null || echo ''"`,
              { encoding: "utf8" }
            ).trim();
            if (lastLines) {
              const lines = lastLines.split("\n").filter((line) => line.trim());
              if (lines.length > 0) {
                const lastLine = lines[lines.length - 1];
                testStatus.lastLine = lastLine;

                // Update progress tracking from last lines (for current test name, total, failures)
                for (let i = 0; i < lines.length; i++) {
                  const line = lines[i];

                  // Look for TeamCity format test count first (most accurate)
                  if (line.includes("##teamcity[testCount")) {
                    const countMatch = line.match(/count='(\d+)'/);
                    if (countMatch) {
                      const teamCityCount = parseInt(countMatch[1]);
                      testStatus.progress.total = teamCityCount;
                      testStatus.message = `Found ${teamCityCount} tests to run`;
                      console.log(
                        `Got test count from TeamCity: ${teamCityCount}`
                      );
                    }
                  }

                  // Look for our clear test execution marker for current test name only
                  if (line.includes("##PHPUNIT_TEST_STARTED##:")) {
                    const testMatch = line.match(
                      /##PHPUNIT_TEST_STARTED##:(.+)/
                    );
                    if (testMatch) {
                      testStatus.progress.current = testMatch[1];
                    }
                  }

                  // Look for test failures
                  if (line.includes("##teamcity[testFailed")) {
                    testStatus.progress.failed++;
                  }

                  // Look for test count at the beginning
                  if (line.includes("PHPUnit") && line.includes("tests")) {
                    const countMatch = line.match(/(\d+)\s+tests?/);
                    if (countMatch) {
                      testStatus.progress.total = parseInt(countMatch[1]);
                    }
                  }

                  // Look for paratest progress format: "63 / 801 (  7%)"
                  const paratestMatch = line.match(/(\d+)\s*\/\s*(\d+)\s*\(/);
                  if (paratestMatch) {
                    const completed = parseInt(paratestMatch[1]);
                    const total = parseInt(paratestMatch[2]);
                    if (total > 0) {
                      testStatus.progress.completed = completed;
                      testStatus.progress.total = total;
                    }
                  }
                }

                // Update status message based on content
                // Check all recent lines for meaningful content
                let meaningfulMessage = null;
                for (let i = lines.length - 1; i >= 0; i--) {
                  const line = lines[i];
                  if (line.match(/✔\s+\w+/)) {
                    const testMatch = line.match(/✔\s+(.+)/);
                    const testName = testMatch ? testMatch[1].trim() : "Test";
                    meaningfulMessage = `Test class passed: ${testName}`;
                    break;
                  } else if (line.match(/✘\s+\w+/)) {
                    const testMatch = line.match(/✘\s+(.+)/);
                    const testName = testMatch ? testMatch[1].trim() : "Test";
                    meaningfulMessage = `Test class failed: ${testName}`;
                    break;
                  } else if (line.match(/^\w+\s+API\s+\(/)) {
                    meaningfulMessage = `Running: ${line.substring(0, 50)}...`;
                    break;
                  } else if (
                    line.includes("FAILURES!") ||
                    line.includes("ERRORS!")
                  ) {
                    meaningfulMessage = "Tests failed!";
                    break;
                  } else if (line.includes("OK (")) {
                    meaningfulMessage = line;
                    break;
                  }
                }

                // Include progress in message if available
                if (testStatus.progress.total > 0) {
                  const progressText = ` (${testStatus.progress.completed}/${testStatus.progress.total})`;
                  if (meaningfulMessage) {
                    testStatus.message = meaningfulMessage + progressText;
                  } else if (testStatus.progress.current) {
                    testStatus.message = `Running: ${testStatus.progress.current}${progressText}`;
                  } else {
                    testStatus.message = `Running tests...${progressText}`;
                  }
                } else if (meaningfulMessage) {
                  testStatus.message = meaningfulMessage;
                } else if (lastLine.length > 80) {
                  // For long lines (likely JSON), just show the last 80 chars
                  testStatus.message =
                    "..." + lastLine.substring(lastLine.length - 80);
                } else if (lastLine.length > 10) {
                  testStatus.message = lastLine;
                }
              }
            }
          } catch (err) {
            // Ignore errors reading the file
          }
        }, 1000);

        testProcess.stdout.on("data", (data) => {
          const text = data.toString();
          fullOutput += text;
          // Don't truncate during execution
          testStatus.logs = fullOutput;
        });

        testProcess.stderr.on("data", (data) => {
          const text = data.toString();
          fullOutput += text;
          testStatus.logs = fullOutput;
        });

        testProcess.on("close", (code) => {
          clearInterval(monitorInterval);

          // Get final output
          try {
            const finalOutput = execSync(
              `docker exec freegle-apiv1-phpunit sh -c "cat ${outputFile} 2>/dev/null || echo ''"`,
              { encoding: "utf8", maxBuffer: 50 * 1024 * 1024 }
            ); // 50MB buffer
            testStatus.logs = finalOutput;
          } catch (err) {
            // Keep existing logs
            console.error("Error reading final output:", err.message);
          }

          // Extract detailed failure and skip information
          const failedTests = [];
          const skippedTests = [];

          // Look for PHPUnit failure details (format: 1) TestClass::testMethod)
          const failureBlocks = testStatus.logs.split(/\n(?=\d+\))/);
          for (const block of failureBlocks) {
            // Skip blocks that contain only fake deadlock exceptions from retry tests
            if (
              block.includes("Faked deadlock exception") &&
              !block.match(/Failed asserting that/)
            ) {
              continue;
            }

            const failureMatch = block.match(/^(\d+)\)\s+(.+?)$/m);
            if (failureMatch) {
              const testName = failureMatch[2];
              // Extract the failure reason
              const assertionMatch = block.match(
                /Failed asserting that (.+?)(?:\n|$)/
              );
              const exceptionMatch = block.match(/Exception: (.+?)(?:\n|$)/);
              const errorMatch = block.match(/Error: (.+?)(?:\n|$)/);

              let reason = "";
              if (assertionMatch) {
                reason = `Failed asserting that ${assertionMatch[1]}`;
              } else if (
                exceptionMatch &&
                !exceptionMatch[1].includes("Faked deadlock exception")
              ) {
                reason = `Exception: ${exceptionMatch[1]}`;
              } else if (errorMatch) {
                reason = `Error: ${errorMatch[1]}`;
              } else if (block.includes("Failed")) {
                // Generic failure detection
                const lines = block.split("\n");
                for (const line of lines) {
                  if (line.includes("Failed") && !line.match(/^\d+\)/)) {
                    reason = line.trim();
                    break;
                  }
                }
              }

              if (reason) {
                failedTests.push({
                  name: testName,
                  reason: reason,
                });
              }
            }
          }

          // Look for skipped tests in output
          const skippedPattern =
            /Test skipped:\s+(.+)|Skipped:\s+(.+)|(.+):\s+This test has not been implemented yet\.|(.+):\s+Skipped/g;
          let skippedMatch;
          while (
            (skippedMatch = skippedPattern.exec(testStatus.logs)) !== null
          ) {
            const skippedTest =
              skippedMatch[1] ||
              skippedMatch[2] ||
              skippedMatch[3] ||
              skippedMatch[4];
            if (skippedTest && !skippedTests.includes(skippedTest)) {
              skippedTests.push(skippedTest);
            }
          }

          // Check for test failures in the output
          const hasFailures =
            testStatus.logs.includes("FAILURES!") ||
            testStatus.logs.includes("ERRORS!") ||
            testStatus.logs.includes("INCOMPLETE!") ||
            testStatus.logs.includes("SKIPPED!") ||
            testStatus.logs.includes("No tests executed!") ||
            (testStatus.logs.includes("Tests:") &&
              testStatus.logs.match(/Failures: [1-9]/)) ||
            (testStatus.logs.includes("Tests:") &&
              testStatus.logs.match(/Errors: [1-9]/));

          // Check if coverage was generated (required for successful test run)
          const noCoverageGenerated =
            testStatus.logs.includes(
              "ERROR: Coverage file was not generated"
            ) ||
            testStatus.logs.includes(
              "Coverage file was not generated at /tmp/phpunit-coverage.xml"
            );

          // Check if tests actually ran by looking for PHPUnit test execution markers
          const testsActuallyRan =
            testStatus.logs.includes("Tests:") &&
            testStatus.logs.includes("Assertions:");
          const testSummaryMatch = testStatus.logs.match(
            /Tests:\s+(\d+),\s+Assertions:\s+(\d+)/
          );
          const testCount = testSummaryMatch
            ? parseInt(testSummaryMatch[1])
            : 0;

          if (
            code === 0 &&
            !hasFailures &&
            !noCoverageGenerated &&
            testsActuallyRan &&
            testCount > 0
          ) {
            testStatus.status = "completed";
            testStatus.message = "✅ PHP tests completed successfully!";
          } else if (code === 0 && noCoverageGenerated) {
            // Tests exited with 0 but coverage wasn't generated - this means tests didn't run properly
            testStatus.status = "failed";
            testStatus.message =
              "❌ PHPUnit exited early without running tests (no coverage generated)";
          } else if (code === 0 && !testsActuallyRan) {
            // Exit code 0 but no test execution summary
            testStatus.status = "failed";
            testStatus.message = "❌ PHPUnit exited without executing tests";
          } else if (code === 0 && testCount === 0) {
            // Exit code 0 but zero tests ran
            testStatus.status = "failed";
            testStatus.message =
              "❌ PHPUnit completed but no tests were executed";
          } else {
            testStatus.status = "failed";

            // Extract detailed failure information
            const summaryMatch = testStatus.logs.match(
              /Tests: (\d+), Assertions: (\d+)(?:, (Failures: \d+))?(?:, (Errors: \d+))?(?:, (Skipped: \d+))?(?:, (Incomplete: \d+))?/
            );
            if (summaryMatch) {
              const parts = [];
              if (summaryMatch[3]) parts.push(summaryMatch[3]);
              if (summaryMatch[4]) parts.push(summaryMatch[4]);
              if (summaryMatch[5]) parts.push(summaryMatch[5]);
              if (summaryMatch[6]) parts.push(summaryMatch[6]);
              testStatus.message = `❌ PHP tests failed - ${parts.join(", ")}`;

              // Add detailed failure information
              if (failedTests.length > 0) {
                testStatus.message += "\n\nFailed Tests:";
                for (const failed of failedTests) {
                  testStatus.message += `\n  • ${failed.name}`;
                  if (failed.reason) {
                    testStatus.message += `\n    ${failed.reason}`;
                  }
                }
              }

              // Add skipped test information
              if (skippedTests.length > 0) {
                testStatus.message += "\n\nSkipped Tests:";
                for (const skipped of skippedTests) {
                  testStatus.message += `\n  • ${skipped}`;
                }
              }
            } else if (testStatus.logs.includes("No tests executed!")) {
              testStatus.message = `❌ No tests matched the filter`;
            } else if (hasFailures) {
              testStatus.message = `❌ PHP tests failed - check logs for details`;

              // Add any found failure details even without summary
              if (failedTests.length > 0) {
                testStatus.message += "\n\nFailed Tests:";
                for (const failed of failedTests) {
                  testStatus.message += `\n  • ${failed.name}`;
                  if (failed.reason) {
                    testStatus.message += `\n    ${failed.reason}`;
                  }
                }
              }
            } else {
              testStatus.message = `❌ PHP tests failed with exit code: ${code}`;
            }
          }
          testStatus.endTime = Date.now();
        });

        testProcess.on("error", (error) => {
          clearInterval(monitorInterval);
          testStatus.status = "failed";
          testStatus.message = "Error running PHP tests: " + error.message;
          testStatus.endTime = Date.now();
        });
      } catch (error) {
        // Response already sent, just log the error
        console.error("Failed to start PHP tests:", error);
        testStatuses.phpTests = {
          status: "failed",
          message: "Failed to start: " + error.message,
          logs: "",
          endTime: Date.now(),
        };
      }
    }); // End of req.on('end')
    return;
  }

  // PHP Test status endpoint
  if (parsedUrl.pathname === "/api/tests/php/status" && req.method === "GET") {
    res.writeHead(200, { "Content-Type": "application/json" });

    if (!testStatuses.phpTests) {
      res.end(
        JSON.stringify({
          status: "idle",
          message: "No PHP tests have been run yet",
        })
      );
      return;
    }

    const testStatus = testStatuses.phpTests;
    const now = Date.now();
    const timeSinceStart = testStatus.startTime
      ? Math.floor((now - testStatus.startTime) / 1000)
      : null;

    res.end(
      JSON.stringify({
        status: testStatus.status,
        message: testStatus.message,
        logs: testStatus.logs, // Don't truncate - return full logs
        startTime: testStatus.startTime,
        endTime: testStatus.endTime,
        timeSinceStart: timeSinceStart,
        lastLine: testStatus.lastLine,
        progress: testStatus.progress || null,
      })
    );
    return;
  }

  // New cached status endpoint
  if (parsedUrl.pathname === "/api/status") {
    const service = parsedUrl.query.service;

    if (!service) {
      res.writeHead(400, { "Content-Type": "application/json" });
      res.end(JSON.stringify({ error: "Missing service parameter" }));
      return;
    }

    const cached = statusCache.get(service);
    if (cached) {
      res.writeHead(200, { "Content-Type": "application/json" });
      res.end(JSON.stringify(cached));
    } else {
      res.writeHead(404, { "Content-Type": "application/json" });
      res.end(JSON.stringify({ error: "Service not found" }));
    }
    return;
  }

  // All services status endpoint
  if (parsedUrl.pathname === "/api/status/all") {
    const allStatus = {};
    for (const [key, value] of statusCache.entries()) {
      allStatus[key] = value;
    }
    res.writeHead(200, { "Content-Type": "application/json" });
    res.end(JSON.stringify(allStatus));
    return;
  }

  if (parsedUrl.pathname === "/api/cpu") {
    const container = parsedUrl.query.container;

    if (!container) {
      res.writeHead(400, { "Content-Type": "application/json" });
      res.end(JSON.stringify({ error: "Missing container parameter" }));
      return;
    }

    try {
      // Get container CPU stats
      const stats = execSync(
        `docker stats ${container} --no-stream --format "{{.CPUPerc}}"`,
        {
          encoding: "utf8",
          timeout: 5000,
        }
      ).trim();

      const cpuPercent = parseFloat(stats.replace("%", "")) || 0;

      res.writeHead(200, { "Content-Type": "application/json" });
      res.end(JSON.stringify({ cpu: cpuPercent, timestamp: Date.now() }));
    } catch (error) {
      res.writeHead(500, { "Content-Type": "application/json" });
      res.end(JSON.stringify({ error: error.message, cpu: 0 }));
    }
    return;
  }

  if (parsedUrl.pathname === "/api/freegle-check") {
    const service = parsedUrl.query.service;

    if (!service) {
      res.writeHead(400, { "Content-Type": "text/plain" });
      res.end("Missing service parameter");
      return;
    }

    try {
      let testUrl, testDescription;

      if (service === "apiv2") {
        testUrl = "http://freegle-apiv2:8192/api/online";
        testDescription = "API v2 online endpoint responding";
      } else if (service === "apiv1") {
        testUrl = "http://freegle-apiv1:80/api/config";
        testDescription = "API v1 config endpoint responding";
      } else if (service === "freegle-dev-local") {
        testUrl = "http://freegle-dev-local:3002/";
        testDescription = "Freegle Dev (Local) site responding";
      } else if (service === "freegle-dev-live") {
        testUrl = "http://freegle-dev-live:3002/";
        testDescription = "Freegle Dev (Live) site responding";
      } else if (service === "freegle-prod-local") {
        testUrl = "http://freegle-prod-local:3003/";
        testDescription = "Freegle Prod site responding";
      } else if (service === "modtools-dev-local") {
        testUrl = "http://modtools-dev-local:3000/";
        testDescription = "ModTools Dev site responding";
      } else if (service === "modtools-dev-live") {
        testUrl = "http://modtools-dev-live:3000/";
        testDescription = "ModTools Dev Live site responding";
      } else if (service === "modtools-prod-local") {
        testUrl = "http://modtools-prod-local:3001/";
        testDescription = "ModTools Prod site responding";
      } else {
        res.writeHead(400, { "Content-Type": "text/plain" });
        res.end("Unknown service");
        return;
      }

      const response = await fetch(testUrl);
      if (response.ok || (service === "apiv1" && response.status === 404)) {
        const statusText = response.ok
          ? response.status
          : `${response.status} (expected)`;
        res.writeHead(200, { "Content-Type": "text/plain" });
        res.end(`success|${testDescription} (HTTP ${statusText})`);
      } else if (response.status === 502 || response.status === 503) {
        res.writeHead(202, { "Content-Type": "text/plain" });
        res.end(`starting|Service building/starting (HTTP ${response.status})`);
      } else {
        res.writeHead(500, { "Content-Type": "text/plain" });
        res.end(`failed|HTTP ${response.status}`);
      }
    } catch (error) {
      // Check if container is running but service not ready (building/starting)
      if (
        error.code === "ECONNREFUSED" ||
        error.message.includes("ECONNREFUSED") ||
        error.message.includes("connect ECONNREFUSED")
      ) {
        try {
          // Get the container name from the service
          let containerName = "";
          if (service === "freegle-dev-local") containerName = "freegle-dev-local";
          else if (service === "freegle-dev-live")
            containerName = "freegle-dev-live";
          else if (service === "freegle-prod-local")
            containerName = "freegle-prod-local";
          else if (service === "modtools-dev-local")
            containerName = "modtools-dev-local";
          else if (service === "modtools-dev-live")
            containerName = "modtools-dev-live";
          else if (service === "modtools-prod-local")
            containerName = "modtools-prod-local";
          else if (service === "apiv1") containerName = "freegle-apiv1";
          else if (service === "apiv2") containerName = "freegle-apiv2";

          if (containerName) {
            const lastLog = execSync(`docker logs ${containerName} --tail=1`, {
              encoding: "utf8",
              timeout: 5000,
            }).trim();
            const shortLog =
              lastLog.length > 80 ? lastLog.substring(0, 80) + "..." : lastLog;
            res.writeHead(202, { "Content-Type": "text/plain" });
            res.end(`starting|Building: ${shortLog || "Starting up..."}`);
          } else {
            res.writeHead(202, { "Content-Type": "text/plain" });
            res.end(`starting|Service building/starting (connection refused)`);
          }
        } catch (logError) {
          res.writeHead(202, { "Content-Type": "text/plain" });
          res.end(`starting|Service building/starting (connection refused)`);
        }
      } else {
        res.writeHead(500, { "Content-Type": "text/plain" });
        res.end(`failed|${error.message}`);
      }
    }
    return;
  }

  if (parsedUrl.pathname === "/api/dev-check") {
    const service = parsedUrl.query.service;

    if (!service) {
      res.writeHead(400, { "Content-Type": "text/plain" });
      res.end("Missing service parameter");
      return;
    }

    try {
      let testUrl, testDescription;

      if (service === "phpmyadmin") {
        testUrl = "http://freegle-phpmyadmin:80/";
        testDescription = "PhpMyAdmin login page accessible";
      } else if (service === "mailpit") {
        testUrl = "http://freegle-mailpit:8025/";
        testDescription = "Mailpit web interface accessible";
      } else {
        res.writeHead(400, { "Content-Type": "text/plain" });
        res.end("Unknown service");
        return;
      }

      const response = await fetch(testUrl);
      if (response.ok) {
        res.writeHead(200, { "Content-Type": "text/plain" });
        res.end(`success|${testDescription} (HTTP ${response.status})`);
      } else {
        res.writeHead(500, { "Content-Type": "text/plain" });
        res.end(`failed|HTTP ${response.status}`);
      }
    } catch (error) {
      res.writeHead(500, { "Content-Type": "text/plain" });
      res.end(`failed|${error.message}`);
    }
    return;
  }

  if (parsedUrl.pathname === "/api/check") {
    const type = parsedUrl.query.type;
    const container = parsedUrl.query.container;

    if (!type || !container) {
      res.writeHead(400, { "Content-Type": "text/plain" });
      res.end("Missing type or container parameter");
      return;
    }

    try {
      let checkResult = "";

      // Use Docker's built-in health check status
      const healthStatus = execSync(
        `docker inspect --format='{{.State.Health.Status}}' ${container}`,
        {
          encoding: "utf8",
          timeout: 3000,
        }
      ).trim();

      if (healthStatus === "healthy") {
        let testDescription = "";
        switch (type) {
          case "mysql":
            testDescription = "MySQL query execution test (SELECT 1)";
            break;
          case "postgres":
            testDescription = "PostgreSQL query execution test (SELECT 1)";
            break;
          case "redis":
            testDescription = "Redis PING command response test";
            break;
          case "mjml":
            testDescription = "MJML compilation server health endpoint";
            break;
          case "beanstalkd":
            testDescription = "Beanstalkd port 11300 connection test";
            break;
          case "spamassassin":
            testDescription = "SpamAssassin port 783 connection test";
            break;
          case "traefik":
            testDescription = "Traefik dashboard HTTP endpoint test";
            break;
          case "tusd":
            testDescription = "TusD upload endpoint responding (HTTP 200/405)";
            break;
          case "playwright":
            testDescription = "Playwright test container ready for execution";
            break;
          case "loki":
            testDescription = "Loki log aggregation ready";
            break;
          default:
            testDescription = "Health check passed";
        }
        res.writeHead(200, { "Content-Type": "text/plain" });
        res.end(`success|${testDescription}`);
      } else {
        res.writeHead(500, { "Content-Type": "text/plain" });
        res.end(`failed|Health status: ${healthStatus}`);
      }
    } catch (error) {
      res.writeHead(500, { "Content-Type": "text/plain" });
      res.end(`failed|${error.message}`);
    }
    return;
  }

  if (parsedUrl.pathname === "/api/logs") {
    const container = parsedUrl.query.container;

    if (!container || !/^[a-zA-Z0-9._-]+$/.test(container)) {
      res.writeHead(400, { "Content-Type": "text/plain" });
      res.end("Invalid container name");
      return;
    }

    try {
      const logs = execSync(`docker logs --tail=20 ${container}`, {
        encoding: "utf8",
        timeout: 5000,
      });
      res.writeHead(200, { "Content-Type": "text/plain" });
      res.end(logs || "Container running but no recent logs");
    } catch (error) {
      // Try to get stderr if stdout is empty
      try {
        const stderrLogs = execSync(`docker logs --tail=20 ${container} 2>&1`, {
          encoding: "utf8",
          timeout: 5000,
        });
        res.writeHead(200, { "Content-Type": "text/plain" });
        res.end(stderrLogs || "Container running but no logs");
      } catch (err2) {
        res.writeHead(500, { "Content-Type": "text/plain" });
        res.end(`Failed to get logs: ${error.message}`);
      }
    }
    return;
  }

  // Serve SSL certificate
  if (parsedUrl.pathname === "/ssl/ca.crt") {
    const certPath = "/app/ssl/ca.crt";
    if (fs.existsSync(certPath)) {
      res.writeHead(200, {
        "Content-Type": "application/x-x509-ca-cert",
        "Content-Disposition": 'attachment; filename="ca.crt"',
      });
      res.end(fs.readFileSync(certPath));
    } else {
      res.writeHead(404, { "Content-Type": "text/plain" });
      res.end("Certificate not found");
    }
    return;
  }

  // Dev Connect page - shows QR code for Freegle Dev app
  if (parsedUrl.pathname === "/dev-connect") {
    // Try to detect host IP from playwright container (has host networking)
    // Priority: DEV_SERVER_HOST env var > auto-detect from playwright > manual entry
    let devServerHost = process.env.DEV_SERVER_HOST || null;
    const devServerPort = process.env.DEV_SERVER_PORT || "3004"; // Default to freegle-dev-live port

    // Auto-detect LAN IP from playwright container which has host networking
    if (!devServerHost) {
      try {
        const ips = execSync(
          'docker exec freegle-playwright hostname -I 2>/dev/null',
          { encoding: 'utf8', timeout: 3000 }
        ).trim().split(' ');

        // Find the 192.168.x.x or 10.x.x.x IP (typical LAN IPs)
        devServerHost = ips.find(ip =>
          ip.startsWith('192.168.') ||
          ip.startsWith('10.') ||
          (ip.startsWith('172.') && !ip.startsWith('172.17.') && !ip.startsWith('172.18.') && !ip.startsWith('172.19.'))
        ) || null;
      } catch (error) {
        console.log('Could not auto-detect LAN IP:', error.message);
      }
    }

    res.writeHead(200, { "Content-Type": "text/html" });
    res.end(`<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Freegle Android Dev App - Connect</title>
  <style>
    * { box-sizing: border-box; }
    body {
      font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
      max-width: 600px;
      margin: 0 auto;
      padding: 20px;
      background: #f5f5f5;
    }
    h1 { color: #5cb85c; text-align: center; margin-bottom: 10px; }
    .subtitle { text-align: center; color: #666; margin-bottom: 30px; }
    .card {
      background: white;
      border-radius: 12px;
      padding: 24px;
      margin-bottom: 20px;
      box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    }
    .url-container {
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 16px;
    }
    .url-display {
      font-family: monospace;
      font-size: 18px;
      background: #f0f0f0;
      padding: 12px 20px;
      border-radius: 8px;
      word-break: break-all;
    }
    .form-group { margin-bottom: 16px; }
    label { display: block; margin-bottom: 8px; font-weight: 500; }
    input[type="text"] {
      width: 100%;
      padding: 12px;
      font-size: 16px;
      border: 2px solid #ddd;
      border-radius: 8px;
    }
    input[type="text"]:focus { border-color: #5cb85c; outline: none; }
    button {
      background: #5cb85c;
      color: white;
      border: none;
      padding: 12px 24px;
      font-size: 16px;
      border-radius: 8px;
      cursor: pointer;
      width: 100%;
    }
    button:hover { background: #4cae4c; }
    .help { font-size: 14px; color: #666; margin-top: 16px; }
    .help ul { margin: 8px 0; padding-left: 20px; }
    .status { padding: 12px; border-radius: 8px; margin-bottom: 16px; }
    .status.success { background: #d4edda; color: #155724; }
    .status.error { background: #f8d7da; color: #721c24; }
    .status.checking { background: #fff3cd; color: #856404; }
    .hidden { display: none; }
    .intro p { margin-bottom: 12px; line-height: 1.5; }
    .comparison-table { width: 100%; border-collapse: collapse; font-size: 14px; }
    .comparison-table th, .comparison-table td { padding: 10px; text-align: left; border-bottom: 1px solid #eee; }
    .comparison-table th { background: #f8f8f8; font-weight: 600; }
    .comparison-table tr.fast td:nth-child(2), .comparison-table tr.fast td:nth-child(3) { color: #28a745; font-weight: 500; }
    .comparison-table tr.slow td:nth-child(2), .comparison-table tr.slow td:nth-child(3) { color: #dc3545; }
  </style>
</head>
<body>
  <h1>Freegle Android Dev App</h1>
  <p class="subtitle">Connect your Android app to the local dev server</p>

  <div style="background: #f8d7da; border: 2px solid #dc3545; border-radius: 8px; padding: 15px; margin-bottom: 20px;">
    <h3 style="color: #dc3545; margin: 0 0 8px 0;">Warning: Production Data</h3>
    <p style="margin: 0; font-size: 14px;">
      The dev server (port 3004) connects to <strong>LIVE Freegle APIs</strong>.
      Any posts, messages, or actions will affect <strong>REAL users and data</strong>.
      Use with caution.
    </p>
  </div>

  <div class="card">
    <h3>Connect Your Phone</h3>
    <p style="margin-bottom: 16px;">Enter the URL <code>http://&lt;your-ip&gt;:3004</code> in the Freegle Dev app on your phone.</p>

    <div class="form-group">
      <label for="hostIp">Your computer's LAN IP address:</label>
      <input type="text" id="hostIp" placeholder="e.g., 192.168.1.100" value="${devServerHost || ""}" readonly style="background: #f5f5f5;">
    </div>
    <div class="form-group">
      <label>Dev server port:</label>
      <input type="text" value="3004" readonly style="background: #f5f5f5;">
    </div>

  </div>

  <div class="card intro">
    <h3>How Live Reload Works</h3>
    <p>The Freegle Dev app loads its web content from your local dev server instead of bundled assets. This means code changes appear instantly without rebuilding the APK, and you can test the same changes via browser and app easily.</p>
    <p style="margin-top: 8px; font-size: 14px; color: #666;"><strong>Android only:</strong> This dev app workflow is for Android. For iOS development, use TestFlight builds.</p>
    <p style="margin-top: 8px; font-size: 14px; color: #666;">The dev app is built automatically on CircleCI and uses a separate package ID (<code>org.ilovefreegle.dev</code>), so it can be installed alongside the production Freegle app on the same device.</p>

    <table class="comparison-table">
      <thead>
        <tr>
          <th>Change Type</th>
          <th>Rebuild APK?</th>
          <th>Time</th>
        </tr>
      </thead>
      <tbody>
        <tr class="fast">
          <td>Vue components, JS, CSS</td>
          <td>No</td>
          <td>Instant (hot reload)</td>
        </tr>
        <tr class="fast">
          <td>Pages, layouts, composables</td>
          <td>No</td>
          <td>Instant (hot reload)</td>
        </tr>
        <tr class="fast">
          <td>Store changes (Pinia)</td>
          <td>No</td>
          <td>Instant (hot reload)</td>
        </tr>
        <tr class="slow">
          <td>Capacitor plugins</td>
          <td>Yes</td>
          <td>~10 mins (CI build)</td>
        </tr>
        <tr class="slow">
          <td>Native Android code</td>
          <td>Yes</td>
          <td>~10 mins (CI build)</td>
        </tr>
      </tbody>
    </table>

    <p style="margin-top: 12px; font-size: 14px; color: #666;">
      <strong>Tip:</strong> For most day-to-day development, you'll never need to rebuild the APK. Only plugin or native code changes require a new build.
    </p>
  </div>

  <div class="card">
    <h3>ADB Setup (Required)</h3>
    <div class="help">
      <p style="margin-bottom: 12px;">The dev app uses <code>adb reverse</code> to forward ports from the phone to your dev machine. This avoids needing to install the full Android SDK - just a minimal ADB tool.</p>

      <strong>Step 1: Install Minimal ADB</strong>
      <p style="margin: 8px 0;">Download and install <a href="https://androidmtk.com/download-minimal-adb-and-fastboot-tool" target="_blank" style="color: #5cb85c;">Minimal ADB and Fastboot</a> (small ~2MB download, no Android SDK needed).</p>

      <strong style="display: block; margin-top: 16px;">Step 2: Connect Phone via USB</strong>
      <p style="margin: 8px 0;">Connect your Android phone via USB and enable USB debugging in Developer Options.</p>

      <strong style="display: block; margin-top: 16px;">Step 3: Run ADB Reverse Command</strong>
      <p style="margin: 8px 0;">Open Command Prompt and run:</p>
      <pre style="background: #1e1e1e; color: #d4d4d4; padding: 12px; border-radius: 6px; overflow-x: auto; font-size: 13px;">adb reverse tcp:3004 tcp:3004</pre>
      <p style="font-size: 12px; color: #888; margin-top: 4px;">This forwards localhost:3004 on the phone to your dev machine. Run this each time you reconnect the phone.</p>

      <strong style="display: block; margin-top: 16px;">Step 4: Start the Dev Server</strong>
      <p style="margin: 8px 0;">Start the <code>freegle-dev-live</code> container from the <a href="/" style="color: #5cb85c;">status page</a>.</p>

      <strong style="display: block; margin-top: 16px;">Step 5: Open the Dev App</strong>
      <p style="margin: 8px 0;">Open the Freegle Dev app on your phone. It will connect to localhost:3004 which ADB forwards to your dev machine.</p>
    </div>
  </div>

  <div class="card">
    <h3>Why ADB Reverse?</h3>
    <div class="help">
      <ul style="margin: 0; padding-left: 20px;">
        <li><strong>No Android SDK needed</strong> - Just install Minimal ADB (~2MB)</li>
        <li><strong>No network configuration</strong> - Works regardless of WiFi/firewall settings</li>
        <li><strong>Reliable</strong> - Unlike mDNS which Android doesn't resolve well</li>
        <li><strong>Secure</strong> - Traffic stays on USB, not broadcast over network</li>
      </ul>
      <p style="margin-top: 12px; font-size: 13px; color: #666;">The only downside is requiring a USB connection, but this is the standard approach for Android development.</p>
    </div>
  </div>

  <div class="card">
    <h3>Known Limitations</h3>
    <div class="help">
      <strong>No Hot Module Replacement (HMR)</strong>
      <p style="margin: 8px 0;">Hot reload doesn't work in the Android WebView due to WebSocket connection issues. After making code changes, tap the refresh icon to reload. This is a known limitation of Capacitor WebView development.</p>
    </div>
  </div>

  <div class="card">
    <h3>Troubleshooting</h3>
    <div class="help">
      <strong>App says "Cannot load localhost:3004"</strong>
      <p style="margin: 8px 0;">The ADB reverse port forwarding resets when you disconnect/reconnect your phone. Re-run the command:</p>
      <pre style="background: #1e1e1e; color: #d4d4d4; padding: 12px; border-radius: 6px; overflow-x: auto; font-size: 13px;">adb reverse tcp:3004 tcp:3004</pre>
      <p style="margin-top: 8px; font-size: 13px;">You can check current port forwards with: <code>adb reverse --list</code></p>

      <strong style="display: block; margin-top: 16px;">Phone not detected by ADB</strong>
      <p style="margin: 8px 0;">Ensure USB debugging is enabled in Developer Options on your phone. You may need to authorize the computer when prompted on the phone.</p>
    </div>
  </div>

</body>
</html>`);
    return;
  }

  // API endpoint to test connection to dev server (server-side, avoids CORS)
  if (parsedUrl.pathname === "/api/dev-connect/test" && req.method === "GET") {
    const ip = parsedUrl.query.ip;
    const port = parsedUrl.query.port || "3002";

    if (!ip) {
      res.writeHead(400, { "Content-Type": "application/json" });
      res.end(JSON.stringify({ error: "Missing ip parameter" }));
      return;
    }

    const devUrl = "http://" + ip + ":" + port;

    try {
      const response = await fetch(devUrl);
      res.writeHead(200, { "Content-Type": "application/json" });
      res.end(
        JSON.stringify({
          success: true,
          url: devUrl,
          status: response.status,
        })
      );
    } catch (error) {
      res.writeHead(200, { "Content-Type": "application/json" });
      res.end(
        JSON.stringify({
          success: false,
          url: devUrl,
          error: error.message,
        })
      );
    }
    return;
  }

  // Serve static files
  const filePath =
    parsedUrl.pathname === "/" ? "/index.html" : parsedUrl.pathname;
  const fullPath = path.join(__dirname, filePath);

  if (fs.existsSync(fullPath) && fs.statSync(fullPath).isFile()) {
    const ext = path.extname(fullPath);
    const contentType =
      ext === ".html"
        ? "text/html"
        : ext === ".js"
        ? "text/javascript"
        : ext === ".css"
        ? "text/css"
        : "text/plain";

    res.writeHead(200, { "Content-Type": contentType });
    res.end(fs.readFileSync(fullPath));
  } else {
    res.writeHead(404, { "Content-Type": "text/plain" });
    res.end("Not found");
  }
});

httpServer.listen(80, "0.0.0.0", () => {
  console.log("Status server running on port 80 with connection pooling");
});

const { execSync } = require("child_process");
const fs = require("fs");
const path = require("path");
const readline = require("readline");
const Database = require("better-sqlite3");

class SentryIntegration {
  /**
   * Sanitize sensitive data from strings before including in PRs, commits, or logs
   * Removes API keys, secrets, tokens, passwords, and other confidential data
   */
  sanitize(text) {
    if (!text) return text;

    let sanitized = text;

    // Pattern 1: URL query parameters with secrets (secret=, key=, token=, apikey=, password=, api_key=)
    // Matches both URL-encoded and plain values
    sanitized = sanitized.replace(/([?&])(secret|key|token|apikey|password|api_key|auth|authorization)=([^&\s)`]+)/gi, '$1$2=REDACTED');

    // Pattern 2: Non-URL parameter style (e.g., "token=value" outside URLs)
    sanitized = sanitized.replace(/(^|[^\w])(secret|key|token|apikey|password|api_key|auth|authorization)=([^\s&),]+)/gi, '$1$2=REDACTED');

    // Pattern 3: Bearer tokens
    sanitized = sanitized.replace(/Bearer\s+[A-Za-z0-9_\-\.=]+/gi, 'Bearer REDACTED');

    // Pattern 4: Authorization headers
    sanitized = sanitized.replace(/(Authorization:\s*)([^\s]+)/gi, '$1REDACTED');

    // Pattern 5: Long alphanumeric strings in URLs (likely API keys) - 20+ chars
    sanitized = sanitized.replace(/(https?:\/\/[^\s]+\/)([A-Za-z0-9]{20,})([\/\s])/g, '$1REDACTED$3');

    // Pattern 6: Common API key patterns in JSON/config formats
    sanitized = sanitized.replace(/(['"])(secret|key|token|apikey|password|api_key)(['"])\s*[:=]\s*(['"])([^'"]{6,})(['"])/gi, '$1$2$3$4REDACTED$6');

    // Pattern 7: MySQL/DB connection strings with passwords
    sanitized = sanitized.replace(/(mysql|postgres|postgresql):\/\/([^:]+):([^@]+)@/gi, '$1://$2:REDACTED@');

    // Pattern 8: AWS-style keys (AKIA... access keys)
    sanitized = sanitized.replace(/AKIA[0-9A-Z]{16}/g, 'AKIA_REDACTED');

    // Pattern 9: Generic long alphanumeric strings that look like secrets (40+ chars, mixed case)
    sanitized = sanitized.replace(/\b[A-Za-z0-9]{40,}\b/g, (match) => {
      // Only redact if it has both upper and lower case (likely a secret)
      if (/[a-z]/.test(match) && /[A-Z]/.test(match)) {
        return 'REDACTED';
      }
      return match;
    });

    return sanitized;
  }

  constructor(config) {
    this.sentryOrgSlug = config.sentryOrgSlug;
    this.sentryAuthToken = config.sentryAuthToken;
    this.pollIntervalMs = config.pollIntervalMs || 15 * 60 * 1000; // 15 minutes default
    this.activeProcessing = new Map();
    this.ignoreAlreadyProcessing = config.ignoreAlreadyProcessing || false; // Flag to skip duplicate check
    this.maxIssuesPerPoll = config.maxIssuesPerPoll || null; // Limit number of issues to process
    this.projectFilter = config.projectFilter || null; // Filter to specific projects
    this.prNumber = config.prNumber || null; // Filter to specific PR number
    this.forceClean = config.forceClean || false; // Discard uncommitted changes without prompting

    // Load Sentry project configurations from environment variables
    this.projects = this.loadProjectsFromEnv();

    // Apply project filter if specified
    if (this.projectFilter && Array.isArray(this.projectFilter)) {
      const filteredProjects = {};
      for (const projectName of this.projectFilter) {
        if (this.projects[projectName]) {
          filteredProjects[projectName] = this.projects[projectName];
        }
      }
      this.projects = filteredProjects;
    }

    // Initialize SQLite database for tracking processed issues
    this.initDatabase();

    console.log("Sentry Integration initialized with projects:", Object.keys(this.projects));
    console.log("Using Claude Code CLI for analysis (no separate API costs)");
    if (this.ignoreAlreadyProcessing) {
      console.log("⚠️ Ignoring 'already processing' checks");
    }
    if (this.maxIssuesPerPoll) {
      console.log(`⚠️ Limited to ${this.maxIssuesPerPoll} issue(s) per poll`);
    }
  }

  /**
   * Initialize SQLite database for tracking processed issues
   */
  initDatabase() {
    const dbPath = process.env.SENTRY_DB_PATH || 'sentry-issues.db';
    this.db = new Database(dbPath);

    // Create tables if they don't exist
    this.db.exec(`
      CREATE TABLE IF NOT EXISTS processed_issues (
        issue_id TEXT PRIMARY KEY,
        module TEXT NOT NULL,
        title TEXT,
        status TEXT NOT NULL,
        attempts INTEGER DEFAULT 1,
        pr_url TEXT,
        pr_number INTEGER,
        error_message TEXT,
        first_processed_at INTEGER NOT NULL,
        last_processed_at INTEGER NOT NULL
      )
    `);

    this.db.exec(`
      CREATE TABLE IF NOT EXISTS processed_pr_comments (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        pr_number INTEGER NOT NULL,
        comment_id TEXT NOT NULL,
        comment_type TEXT NOT NULL,
        comment_body TEXT,
        processed_at INTEGER NOT NULL,
        action_taken TEXT,
        UNIQUE(pr_number, comment_id)
      )
    `);

    this.db.exec(`
      CREATE TABLE IF NOT EXISTS pr_test_failures (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        pr_number INTEGER NOT NULL,
        test_run_id TEXT NOT NULL,
        failure_details TEXT,
        processed_at INTEGER NOT NULL,
        fix_attempted BOOLEAN DEFAULT 0,
        UNIQUE(pr_number, test_run_id)
      )
    `);

    console.log(`SQLite database initialized at ${dbPath}`);
  }

  /**
   * Check if an issue has been processed before
   */
  hasBeenProcessed(issueId) {
    const stmt = this.db.prepare('SELECT status, attempts FROM processed_issues WHERE issue_id = ?');
    const result = stmt.get(issueId);

    if (!result) {
      return { processed: false };
    }

    // Allow retrying if:
    // 1. Status is 'failed' and attempts < 3
    // 2. Status is 'skipped' (couldn't reproduce) - don't retry
    if (result.status === 'failed' && result.attempts < 3) {
      return { processed: false, shouldRetry: true, attempts: result.attempts };
    }

    return {
      processed: true,
      status: result.status,
      attempts: result.attempts
    };
  }

  /**
   * Extract PR number from GitHub PR URL
   */
  extractPRNumber(prUrl) {
    if (!prUrl) return null;
    const match = prUrl.match(/\/pull\/(\d+)/);
    return match ? parseInt(match[1]) : null;
  }

  /**
   * Record that an issue has been processed
   */
  recordProcessedIssue(issueId, moduleKey, title, status, prUrl = null, errorMessage = null) {
    const now = Date.now();
    const prNumber = this.extractPRNumber(prUrl);
    const existing = this.db.prepare('SELECT attempts, first_processed_at FROM processed_issues WHERE issue_id = ?').get(issueId);

    if (existing) {
      // Update existing record
      this.db.prepare(`
        UPDATE processed_issues
        SET status = ?,
            attempts = attempts + 1,
            pr_url = ?,
            pr_number = ?,
            error_message = ?,
            last_processed_at = ?
        WHERE issue_id = ?
      `).run(status, prUrl, prNumber, errorMessage, now, issueId);
    } else {
      // Insert new record
      this.db.prepare(`
        INSERT INTO processed_issues
        (issue_id, module, title, status, pr_url, pr_number, error_message, first_processed_at, last_processed_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
      `).run(issueId, moduleKey, title, status, prUrl, prNumber, errorMessage, now, now);
    }
  }

  /**
   * Get statistics about processed issues
   */
  getProcessedStats() {
    const stats = this.db.prepare(`
      SELECT
        status,
        COUNT(*) as count
      FROM processed_issues
      GROUP BY status
    `).all();

    const total = this.db.prepare('SELECT COUNT(*) as count FROM processed_issues').get();

    return {
      total: total.count,
      byStatus: stats.reduce((acc, row) => {
        acc[row.status] = row.count;
        return acc;
      }, {}),
    };
  }

  /**
   * Clear processed issues (for testing or reset)
   */
  clearProcessedIssues() {
    this.db.prepare('DELETE FROM processed_issues').run();
    console.log('Cleared all processed issues from database');
  }

  /**
   * Load project configurations from environment variables
   * Format: SENTRY_PROJECTS=php:6119406:php-api:/project/iznik-server,go:4505568012730368:go-api:/project/iznik-server-go,...
   */
  loadProjectsFromEnv() {
    const projectsEnv = process.env.SENTRY_PROJECTS;

    if (!projectsEnv) {
      console.warn("SENTRY_PROJECTS not configured, using default configuration");
      // Return default configuration
      return {
        php: {
          projectId: "6119406",
          projectSlug: "php",
          repoPath: "/home/edward/FreegleDockerWSL/iznik-server",
          testCommand: "curl -X POST http://localhost:8081/api/tests/php"
        },
        go: {
          projectId: "4505568012730368",
          projectSlug: "go",
          repoPath: "/home/edward/FreegleDockerWSL/iznik-server-go",
          testCommand: "curl -X POST http://localhost:8081/api/tests/go"
        },
        nuxt3: {
          projectId: "4504083802226688",
          projectSlug: "nuxt3",
          repoPath: "/home/edward/FreegleDockerWSL/iznik-nuxt3",
          testCommand: "curl -X POST http://localhost:8081/api/tests/playwright"
        },
        capacitor: {
          projectId: "4506643536609280",
          projectSlug: "capacitor",
          repoPath: "/home/edward/FreegleDockerWSL/iznik-nuxt3",
          testCommand: "curl -X POST http://localhost:8081/api/tests/playwright"
        },
        modtools: {
          projectId: "4506712427855872",
          projectSlug: "modtools",
          repoPath: "/home/edward/FreegleDockerWSL/iznik-nuxt3-modtools",
          testCommand: "curl -X POST http://localhost:8081/api/tests/playwright"
        },
      };
    }

    // Parse environment variable
    const projects = {};
    const projectConfigs = projectsEnv.split(',');

    for (const configStr of projectConfigs) {
      const [key, projectId, projectSlug, repoPath] = configStr.trim().split(':');

      if (!key || !projectId || !projectSlug || !repoPath) {
        console.warn(`Invalid project configuration: ${configStr}`);
        continue;
      }

      // Determine test command based on repository
      let testCommand;
      if (repoPath.includes('iznik-server-go')) {
        testCommand = "curl -X POST http://localhost:8081/api/tests/go";
      } else if (repoPath.includes('iznik-server')) {
        testCommand = "curl -X POST http://localhost:8081/api/tests/php";
      } else {
        testCommand = "curl -X POST http://localhost:8081/api/tests/playwright";
      }

      projects[key] = {
        projectId,
        projectSlug,
        repoPath,
        testCommand,
      };
    }

    return projects;
  }

  /**
   * Start polling Sentry for issues
   */
  start() {
    console.log(`Starting Sentry integration with ${this.pollIntervalMs}ms poll interval`);

    // Do an initial poll after 30 seconds
    setTimeout(() => {
      this.pollSentryIssues();
    }, 30000);

    // Then poll at regular intervals
    setInterval(() => {
      this.pollSentryIssues();
    }, this.pollIntervalMs);
  }

  /**
   * Poll Sentry API for new high-priority issues
   */
  async pollSentryIssues() {
    try {
      console.log("Polling Sentry for high-priority issues...");

      let totalProcessedCount = 0;

      for (const [moduleKey, project] of Object.entries(this.projects)) {
        try {
          const issues = await this.fetchSentryIssues(project.projectSlug);
          console.log(`Found ${issues.length} unresolved issues in ${project.projectSlug}`);

          // Add delay between projects to avoid rate limits
          await this.sleep(2000);

          for (const issue of issues) {
            // Check if we've hit the max issues limit
            if (this.maxIssuesPerPoll && totalProcessedCount >= this.maxIssuesPerPoll) {
              console.log(`Reached max issues limit (${this.maxIssuesPerPoll}), stopping poll`);
              return;
            }

            // Check if already processed using database (unless flag set to ignore)
            if (!this.ignoreAlreadyProcessing) {
              const processedCheck = this.hasBeenProcessed(issue.id);

              if (processedCheck.processed) {
                console.log(`Skipping issue ${issue.id} - already processed (${processedCheck.status}, ${processedCheck.attempts} attempts)`);
                continue;
              }
            }

            // Skip if currently processing
            if (this.activeProcessing.has(issue.id)) {
              continue;
            }

            // Check if issue meets criteria (high priority/frequent)
            if (this.shouldProcessIssue(issue)) {
              if (!this.ignoreAlreadyProcessing) {
                const processedCheck = this.hasBeenProcessed(issue.id);
                if (processedCheck.shouldRetry) {
                  console.log(`Retrying issue ${issue.id} (attempt ${processedCheck.attempts + 1}/3): ${issue.title}`);
                } else {
                  console.log(`Processing issue ${issue.id}: ${issue.title}`);
                }
              } else {
                console.log(`Processing issue ${issue.id}: ${issue.title}`);
              }

              this.activeProcessing.set(issue.id, { module: moduleKey, startTime: Date.now() });

              // Process sequentially (not in parallel)
              try {
                await this.processIssue(issue, moduleKey, project);
                this.activeProcessing.delete(issue.id);
                totalProcessedCount++;
              } catch (error) {
                console.error(`Failed to process issue ${issue.id}:`, error);
                this.activeProcessing.delete(issue.id);
              }

              // Add delay between processing issues to avoid rate limits (3 seconds)
              await this.sleep(3000);
            }
          }
        } catch (error) {
          console.error(`Error fetching issues for ${project.projectSlug}:`, error.message);
        }
      }
    } catch (error) {
      console.error("Error polling Sentry:", error);
    }
  }

  /**
   * Sleep for specified milliseconds
   */
  sleep(ms) {
    return new Promise(resolve => setTimeout(resolve, ms));
  }

  /**
   * Fetch issues from Sentry REST API
   */
  async fetchSentryIssues(projectSlug) {
    const url = `https://sentry.io/api/0/projects/${this.sentryOrgSlug}/${projectSlug}/issues/`;
    const params = new URLSearchParams({
      query: 'is:unresolved',
      statsPeriod: '24h',
    });

    const response = await fetch(`${url}?${params}`, {
      headers: {
        'Authorization': `Bearer ${this.sentryAuthToken}`,
      },
    });

    if (!response.ok) {
      throw new Error(`Sentry API error: ${response.status} ${response.statusText}`);
    }

    return await response.json();
  }

  /**
   * Determine if issue should be processed (high priority/frequent)
   */
  shouldProcessIssue(issue) {
    // Check event count in last 24h (frequent errors)
    const eventCount = issue.count || 0;
    if (eventCount >= 10) {
      return true;
    }

    // Check priority/level
    if (issue.level === 'error' || issue.level === 'fatal') {
      return true;
    }

    // Check if marked as high priority
    if (issue.priority === 'high') {
      return true;
    }

    return false;
  }

  /**
   * Process a single Sentry issue
   */
  async processIssue(issue, moduleKey, project) {
    console.log(`\n=== Processing Sentry Issue ===`);
    console.log(`Module: ${moduleKey}`);
    console.log(`Issue: ${this.sanitize(issue.title)}`);
    console.log(`Events (24h): ${issue.count}`);
    console.log(`Level: ${issue.level}`);

    // Store original branch so we can restore it after processing
    const originalBranch = execSync(`cd ${project.repoPath} && git rev-parse --abbrev-ref HEAD`, {
      encoding: 'utf8',
      timeout: 5000,
    }).trim();

    try {
      // Check if another instance is already processing this (unless flag set to ignore)
      if (!this.ignoreAlreadyProcessing) {
        const alreadyProcessing = await this.checkIfAlreadyProcessing(issue.id);
        if (alreadyProcessing) {
          console.log(`Issue ${issue.id} is already being processed by another instance (started ${alreadyProcessing.timeAgo})`);
          return;
        }
      } else {
        console.log(`Bypassing 'already processing' check (ignoreAlreadyProcessing=true)`);
      }

      // Processing issue
      console.log(`Processing issue ${issue.id}...`);

      // Fetch detailed issue information
      const issueDetails = await this.fetchIssueDetails(issue);

      // Get relevant code context
      const codeContext = await this.getCodeContext(issue, project);

      // Analyze with Claude
      const analysis = await this.analyzeWithClaude(issue, issueDetails, codeContext, moduleKey);

      if (!analysis.canFix) {
        console.log(`Skipping - cannot fix: ${analysis.reason}`);
        this.recordProcessedIssue(issue.id, moduleKey, issue.title, 'skipped', null, analysis.reason);
        return;
      }

      if (analysis.confidence !== 'high') {
        console.log(`Skipping - confidence too low (${analysis.confidence}): ${analysis.reason}`);
        this.recordProcessedIssue(issue.id, moduleKey, issue.title, 'skipped', null, `Low confidence: ${analysis.reason}`);
        return;
      }

      if (analysis.fixType !== 'simple') {
        console.log(`Skipping - fix is not simple (${analysis.fixType}): ${analysis.reason}`);
        this.recordProcessedIssue(issue.id, moduleKey, issue.title, 'skipped', null, `Complex fix: ${analysis.reason}`);
        return;
      }

      console.log("Fix is simple and high confidence. Proceeding...");
      console.log("Checking for existing PRs...");

      // Check for existing PRs that might already fix this
      const existingPR = await this.checkForExistingPR(issue, project, analysis);

      // Check if we should skip this issue (closed PR found)
      if (existingPR && existingPR.skip) {
        console.log(`⏭️  Skipping issue: ${existingPR.reason}`);
        this.recordProcessedIssue(issue.id, moduleKey, issue.title, 'skipped', null, existingPR.reason);
        return;
      }

      // Apply the fix (no tests, just the code change)
      const fixResult = await this.applyFix(analysis, project, moduleKey, existingPR, issue);

      // Check if fix was applied successfully
      if (!fixResult.success) {
        console.log(`❌ Fix failed to apply: ${fixResult.error}`);
        this.recordProcessedIssue(issue.id, moduleKey, issue.title, 'error', null, `Failed to apply fix: ${fixResult.error}`);
        return;
      }

      // For PHP/Go projects, try to generate and run a test locally
      const testResult = await this.generateAndRunTest(analysis, project, moduleKey, issue);

      // If test was added successfully, need to push again
      if (testResult.passed) {
        console.log("Pushing updated commit with test...");
        const pushCmd = existingPR
          ? `cd ${project.repoPath} && git push --force-with-lease origin ${fixResult.branchName}`
          : `cd ${project.repoPath} && git push origin ${fixResult.branchName}`;
        execSync(pushCmd, { encoding: 'utf8', timeout: 30000 });
      }

      // Create or update PR
      if (existingPR) {
        console.log(`✅ Updated existing PR #${existingPR.number}: ${existingPR.url}`);
        this.recordProcessedIssue(issue.id, moduleKey, issue.title, 'updated', existingPR.url, 'Updated existing PR with suggested fix');
      } else {
        console.log("Creating PR with suggested fix.");
        await this.createPR(analysis, project, moduleKey, fixResult, issue, testResult);
        this.recordProcessedIssue(issue.id, moduleKey, issue.title, 'success', fixResult.prUrl);
      }

      console.log(`✅ Successfully processed issue ${issue.id}`);

    } catch (error) {
      console.error(`Error processing issue ${issue.id}:`, error);
      this.recordProcessedIssue(issue.id, moduleKey, issue.title, 'error', null, error.message);
    } finally {
      // Always restore original branch
      try {
        console.log(`Restoring original branch: ${originalBranch}`);
        execSync(`cd ${project.repoPath} && git checkout ${originalBranch}`, {
          encoding: 'utf8',
          timeout: 5000,
        });
      } catch (error) {
        console.error(`Warning: Could not restore original branch ${originalBranch}:`, error.message);
      }
    }
  }

  /**
   * Check for existing PRs that might already fix this issue
   */
  async checkForExistingPR(issue, project, analysis) {
    try {
      console.log("Checking for existing PRs...");

      // Only get OPEN PRs (we don't want to update closed ones)
      const openPRsJson = execSync(`cd ${project.repoPath} && gh pr list --json number,title,url,body,headRefName,state --limit 50`, {
        encoding: 'utf8',
        timeout: 30000,
      });

      const openPRs = JSON.parse(openPRsJson);

      // Extract keywords from Sentry issue for matching
      const keywords = this.extractKeywords(issue, analysis);

      // Search for OPEN PRs that might be related
      for (const pr of openPRs) {
        const prText = `${pr.title} ${pr.body || ''}`.toLowerCase();

        // Check if PR mentions similar keywords or error messages
        for (const keyword of keywords) {
          if (prText.includes(keyword.toLowerCase())) {
            console.log(`Found potential match: OPEN PR #${pr.number} - ${pr.title}`);
            return {
              number: pr.number,
              title: pr.title,
              url: pr.url,
              branchName: pr.headRefName,
              state: pr.state,
              matchedKeyword: keyword,
              isOpen: true
            };
          }
        }
      }

      // No open PRs found - check closed PRs to avoid re-attempting rejected fixes
      const closedPRsJson = execSync(`cd ${project.repoPath} && gh pr list --state closed --json number,title,url,body,closedAt,mergedAt --limit 50`, {
        encoding: 'utf8',
        timeout: 30000,
      });

      const closedPRs = JSON.parse(closedPRsJson).filter(pr => {
        // Only check PRs closed in last 30 days
        const closedDate = new Date(pr.closedAt);
        const thirtyDaysAgo = new Date(Date.now() - 30 * 24 * 60 * 60 * 1000);
        return closedDate > thirtyDaysAgo;
      });

      for (const pr of closedPRs) {
        const prText = `${pr.title} ${pr.body || ''}`.toLowerCase();

        for (const keyword of keywords) {
          if (prText.includes(keyword.toLowerCase())) {
            // Check if PR was merged or just closed
            if (pr.mergedAt) {
              console.log(`Found MERGED PR #${pr.number}: ${pr.title} - issue may already be fixed`);
              // Return special marker to skip this issue
              return { skip: true, reason: `Already fixed by merged PR #${pr.number}` };
            } else {
              console.log(`Found CLOSED (not merged) PR #${pr.number}: ${pr.title} - skipping to avoid re-attempting rejected fix`);
              // Return special marker to skip this issue
              return { skip: true, reason: `Previous fix attempt rejected (PR #${pr.number} closed without merging)` };
            }
          }
        }
      }

      return null;

    } catch (error) {
      console.error("Error checking for existing PRs:", error.message);
      // Don't fail the whole process if PR check fails
      return null;
    }
  }

  /**
   * Extract keywords from Sentry issue for PR matching
   */
  extractKeywords(issue, analysis) {
    const keywords = [];

    // Add issue title words (longer than 4 chars)
    const titleWords = issue.title.split(/\s+/).filter(w => w.length > 4);
    keywords.push(...titleWords);

    // Add root cause keywords
    if (analysis.rootCause) {
      const causeWords = analysis.rootCause.split(/\s+/).filter(w => w.length > 4);
      keywords.push(...causeWords.slice(0, 3)); // Top 3 words
    }

    // Add error type
    if (issue.metadata?.type) {
      keywords.push(issue.metadata.type);
    }

    // Add file paths from fix
    if (analysis.fixFiles) {
      analysis.fixFiles.forEach(f => {
        const filename = f.path.split('/').pop();
        if (filename) keywords.push(filename);
      });
    }

    // Remove duplicates and common words
    const commonWords = ['error', 'undefined', 'null', 'function', 'issue', 'fixed'];
    return [...new Set(keywords)].filter(k => !commonWords.includes(k.toLowerCase()));
  }

  /**
   * Fetch detailed issue information from Sentry
   */
  async fetchIssueDetails(issue) {
    const url = `https://sentry.io/api/0/issues/${issue.id}/`;
    const response = await fetch(url, {
      headers: {
        'Authorization': `Bearer ${this.sentryAuthToken}`,
      },
    });

    if (!response.ok) {
      throw new Error(`Failed to fetch issue details: ${response.status}`);
    }

    const details = await response.json();

    // Add delay after Sentry API call
    await this.sleep(500);

    // Also fetch latest event for more context
    const eventsUrl = `https://sentry.io/api/0/issues/${issue.id}/events/latest/`;
    const eventResponse = await fetch(eventsUrl, {
      headers: {
        'Authorization': `Bearer ${this.sentryAuthToken}`,
      },
    });

    if (eventResponse.ok) {
      details.latestEvent = await eventResponse.json();
      // Add delay after Sentry API call
      await this.sleep(500);
    }

    return details;
  }

  /**
   * Get code context for the issue
   */
  async getCodeContext(issue, project) {
    // Extract file paths from stack trace
    const stackFrames = issue.culprit ? [issue.culprit] : [];

    // Try to read relevant files
    const context = {
      files: [],
      stackTrace: issue.metadata?.value || '',
    };

    // Read files mentioned in stack trace (if accessible)
    for (const frame of stackFrames) {
      try {
        const filePath = path.join(project.repoPath, frame);
        if (fs.existsSync(filePath)) {
          const content = fs.readFileSync(filePath, 'utf8');
          context.files.push({
            path: frame,
            content: content.split('\n').slice(0, 200).join('\n'), // First 200 lines
          });
        }
      } catch (error) {
        // File might not be accessible
      }
    }

    return context;
  }

  /**
   * Retry wrapper for operations that might timeout
   */
  async retryWithTimeout(operation, operationName, maxRetries = 2) {
    for (let attempt = 1; attempt <= maxRetries; attempt++) {
      try {
        console.log(`${operationName} (attempt ${attempt}/${maxRetries})...`);
        return await operation();
      } catch (error) {
        const isTimeout = error.message && (error.message.includes('ETIMEDOUT') || error.message.includes('timeout'));
        const isLastAttempt = attempt === maxRetries;

        if (isTimeout && !isLastAttempt) {
          console.warn(`${operationName} timed out, retrying in 10 seconds...`);
          await new Promise(resolve => setTimeout(resolve, 10000)); // Wait 10s before retry
          continue;
        }

        throw error; // Re-throw on last attempt or non-timeout errors
      }
    }
  }

  /**
   * Analyze issue with Claude Code CLI
   */
  async analyzeWithClaude(issue, details, codeContext, moduleKey) {
    console.log("Analyzing issue with Claude Code CLI...");

    return this.retryWithTimeout(async () => {
      return await this.analyzeWithClaudeInternal(issue, details, codeContext, moduleKey);
    }, 'Claude analysis', 2);
  }

  /**
   * Internal Claude analysis (retryable) - uses Task agents
   */
  async analyzeWithClaudeInternal(issue, details, codeContext, moduleKey) {
    // Sanitize all potentially sensitive data before sending to Claude
    const sanitizedTitle = this.sanitize(issue.title);
    const sanitizedErrorMessage = this.sanitize(details.metadata?.value || issue.title);
    const sanitizedStackTrace = this.sanitize(JSON.stringify(details.latestEvent?.entries?.find(e => e.type === 'exception'), null, 2));
    const sanitizedCodeContext = this.sanitize(JSON.stringify(codeContext, null, 2));

    const prompt = `IMPORTANT: Use Task agents to diagnose this Sentry production error. Use the Explore agent to find relevant code in the repository.

⏱️ **TIME CONSTRAINT: Complete this analysis within 10 minutes. Work efficiently.**

**Your task:**
1. Use Task tool with Explore agent to find code related to this error
2. Analyze the root cause
3. Determine if this is a SIMPLE fix (missing parameter, typo, null check within a single method)
4. If simple, propose a fix. If complex, skip.

**Module:** ${moduleKey}
**Repository Path:** Based on module (php: iznik-server, go: iznik-server-go, nuxt3/capacitor/modtools: iznik-nuxt3*)
**Issue:** ${sanitizedTitle}
**Event Count (24h):** ${issue.count}
**Level:** ${issue.level}

**Error Message:**
${sanitizedErrorMessage}

**Stack Trace:**
${sanitizedStackTrace}

**Initial Code Context:**
${sanitizedCodeContext}

**ONLY PROCEED WITH FIX IF:**
- The error is caused by something obvious: missing parameter, typo, missing null check
- The fix is within a SINGLE METHOD (no multi-file refactoring)
- You are 90%+ confident the fix will work
- Examples: array_key_exists() null parameter → add null check, missing function argument → add default, typo in variable name → fix typo

**SKIP IF:**
- Error requires understanding complex business logic
- Fix needs changes across multiple methods or files
- Root cause is unclear or requires deep investigation
- You're less than 90% confident

**Output Format (JSON only, no markdown):**
{
  "rootCause": "Brief explanation of what's causing the error",
  "canFix": true/false,
  "confidence": "high/low (high = 90%+ confident fix will work)",
  "fixType": "simple/complex (simple = single method, obvious fix)",
  "fix": "High-level explanation of the fix",
  "fixFiles": [
    {
      "path": "relative/path/to/file.php",
      "changes": [
        {
          "type": "replace",
          "lines": "111-111",
          "old": "exact code to find and replace (including whitespace)",
          "new": "exact replacement code (including whitespace)"
        }
      ]
    }
  ],
  "reason": "Explanation if canFix=false or confidence=low"
}

**IMPORTANT for fixFiles format:**
- Each change must have type "replace" with exact "old" and "new" code snippets
- Include enough context in "old" to make it unique in the file
- Preserve exact indentation and whitespace in both "old" and "new"
- The "lines" field is for reference only - actual replacement uses "old" code matching

CRITICAL: Your final message MUST be valid JSON only (no markdown, no explanation).`;

    // Write prompt to temp file to avoid shell escaping issues
    const tempPromptFile = `/tmp/sentry-prompt-${Date.now()}.txt`;
    fs.writeFileSync(tempPromptFile, prompt);

    try {
      // Invoke Claude Code CLI with -p flag to enable Task agents
      console.log("Invoking Claude Code with Task agents (max 10 minutes)...");

      // Only use --dangerously-skip-permissions if not running as root
      const isRoot = process.getuid && process.getuid() === 0;
      const skipPermissionsFlag = isRoot ? '' : '--dangerously-skip-permissions';
      if (isRoot) {
        console.log("Running as root - Claude will prompt for permissions");
      }

      const response = execSync(`claude -p "$(cat ${tempPromptFile})" ${skipPermissionsFlag}`, {
        encoding: 'utf8',
        maxBuffer: 20 * 1024 * 1024, // 20MB buffer for Task agent outputs
        timeout: 900000, // 15 minute timeout (Task agents need more time)
        killSignal: 'SIGTERM',
        cwd: process.cwd(),
      });

      // Clean up temp file
      fs.unlinkSync(tempPromptFile);

      console.log("Claude Code response received (with Task agent analysis)");

      // Extract JSON from response (handle markdown code blocks)
      const jsonMatch = response.match(/```json\n([\s\S]+?)\n```/) || response.match(/\{[\s\S]+\}/);
      if (!jsonMatch) {
        throw new Error("Could not parse Claude response as JSON");
      }

      const analysis = JSON.parse(jsonMatch[1] || jsonMatch[0]);
      console.log("Claude analysis complete:",
        analysis.canFix ? `Can fix (confidence: ${analysis.confidence}, type: ${analysis.fixType})` : `Cannot fix (${analysis.reason})`);

      return analysis;
    } catch (error) {
      console.error("Error invoking Claude Code CLI:", error.message);

      // If Claude Code CLI fails, provide a fallback response
      throw new Error(`Claude Code CLI failed: ${error.message}. Make sure 'claude' CLI is installed and configured.`);
    }
  }


  /**
   * Apply code changes for the fix and create PR
   */
  async applyFix(analysis, project, moduleKey, existingPR = null, issue = null) {
    console.log("Applying suggested fix...");

    let branchName;

    try {
      // Check for uncommitted changes before switching branches
      const gitStatus = execSync(`cd ${project.repoPath} && git status --porcelain`, {
        encoding: "utf8",
        timeout: 10000,
      }).trim();

      if (gitStatus) {
        console.log(`\n⚠️  Uncommitted changes detected in ${project.repoPath}:`);
        console.log(gitStatus.split('\n').map(l => `   ${l}`).join('\n'));

        if (this.forceClean) {
          console.log(`   --force-clean specified, discarding changes...`);
          execSync(`cd ${project.repoPath} && git restore . && git clean -fd`, {
            encoding: "utf8",
            timeout: 10000,
          });
        } else {
          // Prompt user for confirmation
          const rl = readline.createInterface({
            input: process.stdin,
            output: process.stdout,
          });

          const answer = await new Promise((resolve) => {
            rl.question('   Discard these changes and continue? (y/n): ', resolve);
          });
          rl.close();

          if (answer.toLowerCase() !== 'y' && answer.toLowerCase() !== 'yes') {
            throw new Error('Aborted: uncommitted changes in working directory. Commit or stash them, or use --force-clean to discard.');
          }

          execSync(`cd ${project.repoPath} && git restore . && git clean -fd`, {
            encoding: "utf8",
            timeout: 10000,
          });
        }
      }

      if (existingPR) {
        console.log(`Found existing PR #${existingPR.number}, resetting branch to origin/master: ${existingPR.branchName}`);
        branchName = existingPR.branchName;
        // Fetch both master and the PR branch, then reset to origin/master for a fresh fix attempt
        execSync(`cd ${project.repoPath} && git fetch origin master ${branchName} && git checkout ${branchName} && git reset --hard origin/master`, {
          encoding: "utf8",
          timeout: 10000,
        });
      } else {
        branchName = `sentry-auto-fix-${Date.now()}`;
        // Create branch from origin/master to avoid including local uncommitted work
        execSync(`cd ${project.repoPath} && git fetch origin master && git checkout -b ${branchName} origin/master`, {
          encoding: "utf8",
          timeout: 10000,
        });
      }

      // Apply the fix using Claude Code Task agent
      console.log("🤖 Using Task agent to apply fix...");

      // Sanitize analysis data to prevent secrets from appearing in temporary files
      const sanitizedRootCause = this.sanitize(analysis.rootCause);
      const sanitizedFix = this.sanitize(analysis.fix);

      const fixPrompt = `You are applying a fix for a Sentry production error.

**Root Cause:** ${sanitizedRootCause}

**Fix Description:** ${sanitizedFix}

**Files to modify:**
${analysis.fixFiles.map(f => {
  const changes = f.changes.map(c => `lines ${c.lines}`).join(', ');
  return `- ${f.path}: ${changes}`;
}).join('\n')}

**Detailed Changes:**
${analysis.fixFiles.map(f => {
  return `File: ${f.path}\n${f.changes.map(c => {
    return `  Lines ${c.lines}:\n  OLD:\n${this.sanitize(c.old)}\n  NEW:\n${this.sanitize(c.new)}`;
  }).join('\n')}`;
}).join('\n\n')}

**Current working directory:** ${project.repoPath}

**TASK:** Apply the fix described above to the files.
- Read each file to understand the current code
- Use the Edit tool to make the exact changes specified
- The changes may not match exactly due to whitespace - use your judgment to find the right location
- Be thorough and careful to preserve existing functionality

The branch (${branchName}) is already checked out. Apply the changes directly to the files.`;

      const taskPromptFile = `/tmp/task-apply-fix-${Date.now()}.txt`;
      fs.writeFileSync(taskPromptFile, fixPrompt);

      let changesApplied = false;

      try {
        const taskResponse = execSync(
          `claude -p "$(cat ${taskPromptFile})" --dangerously-skip-permissions`,
          {
            encoding: 'utf8',
            maxBuffer: 20 * 1024 * 1024,
            timeout: 600000, // 10 minutes
            cwd: project.repoPath,
          }
        );

        fs.unlinkSync(taskPromptFile);

        console.log(`  ✓ Task agent completed fix application`);

        // Check if any files were modified
        const gitStatus = execSync(`cd ${project.repoPath} && git status --porcelain`, { encoding: 'utf8' });
        if (gitStatus.trim()) {
          changesApplied = true;
          console.log(`  ✓ Task agent made changes to files`);
        } else {
          console.log(`  ⚠ Task agent didn't modify any files`);
        }

      } catch (taskError) {
        console.error(`  ❌ Task agent failed: ${taskError.message}`);
        if (fs.existsSync(taskPromptFile)) {
          fs.unlinkSync(taskPromptFile);
        }

        // Check if this is a Claude ToS error - provide helpful message
        const errorOutput = taskError.stdout || taskError.stderr || taskError.message || '';
        if (errorOutput.includes('Consumer Terms') || errorOutput.includes('Privacy Policy') ||
            errorOutput.includes('must run `claude`') || errorOutput.includes('review the updated terms')) {
          throw new Error('Claude requires Terms of Service acceptance - run "claude" manually to accept, then retry');
        }

        throw new Error(`Task agent failed to apply fix: ${taskError.message}`);
      }

      if (!changesApplied) {
        throw new Error('No changes were applied by Task agent');
      }

      // Commit and push (sanitize all data to avoid exposing secrets)
      const commitMessage = `Suggested fix for Sentry issue: ${this.sanitize(issue.title)}\n\nRoot cause: ${this.sanitize(analysis.rootCause)}\nFix: ${this.sanitize(analysis.fix)}\n\nConfidence: ${analysis.confidence}\nFix type: ${analysis.fixType}\n\nSentry issue: ${issue.permalink}`;

      // Only add the specific files that were identified for modification
      // This prevents accidentally committing other changed files in the working directory
      // Note: fixFiles paths are relative to FreegleDockerWSL (e.g., "iznik-server/include/foo.php")
      // but we're running git add from within project.repoPath, so strip the repo directory prefix
      const repoDir = path.basename(project.repoPath);
      const filesToAdd = analysis.fixFiles.map(f => {
        // If path starts with the repo directory name, strip it
        if (f.path.startsWith(repoDir + '/')) {
          return f.path.substring(repoDir.length + 1);
        }
        return f.path;
      }).join(' ');
      execSync(`cd ${project.repoPath} && git add ${filesToAdd}`, {
        encoding: "utf8",
        timeout: 10000,
      });

      // Write commit message to file to avoid shell escaping issues with backticks and special chars
      const commitMsgFile = `/tmp/sentry-commit-msg-${Date.now()}.txt`;
      fs.writeFileSync(commitMsgFile, commitMessage);
      try {
        execSync(`cd ${project.repoPath} && git commit -F "${commitMsgFile}"`, {
          encoding: "utf8",
          timeout: 10000,
        });
      } finally {
        fs.unlinkSync(commitMsgFile);
      }

      // Force push if updating existing PR (since we reset the branch to origin/master)
      const pushCmd = existingPR
        ? `cd ${project.repoPath} && git push --force-with-lease -u origin ${branchName}`
        : `cd ${project.repoPath} && git push -u origin ${branchName}`;
      execSync(pushCmd, {
        encoding: "utf8",
        timeout: 30000,
      });

      console.log(`✓ Pushed fix to branch: ${branchName}`);

      return {
        success: true,
        branchName,
        existingPR: existingPR,
      };

    } catch (error) {
      console.error("Error applying fix:", error);
      return {
        success: false,
        branchName,
        error: error.message,
      };
    }
  }

  /**
   * Generate and run a test for PHP/Go fixes
   * Returns { success: boolean, testFile: string|null, error: string|null }
   */
  async generateAndRunTest(analysis, project, moduleKey, issue) {
    // Only run tests for PHP and Go projects
    if (moduleKey !== 'php' && moduleKey !== 'go') {
      return { success: true, testFile: null, skipped: true, reason: 'Tests only run for PHP/Go projects' };
    }

    console.log(`\n🧪 Generating test for ${moduleKey} fix...`);

    // Check if docker container is available
    const containerName = moduleKey === 'php' ? 'freegle-apiv1' : 'freegle-apiv2';
    try {
      execSync(`docker ps --format '{{.Names}}' | grep -q ${containerName}`, {
        encoding: 'utf8',
        timeout: 5000,
      });
    } catch (e) {
      console.log(`   ⚠️ Docker container ${containerName} not running - skipping local test`);
      return { success: true, testFile: null, skipped: true, reason: `Docker container ${containerName} not available` };
    }

    // Generate test using Claude
    const testPrompt = this.buildTestPrompt(analysis, project, moduleKey, issue);
    let testResult = null;
    let lastError = null;

    // Try up to 2 times to generate and run a passing test
    for (let attempt = 1; attempt <= 2; attempt++) {
      console.log(`   Attempt ${attempt}/2: Generating test...`);

      try {
        const testCode = await this.generateTest(testPrompt, moduleKey, attempt > 1 ? lastError : null);

        if (!testCode) {
          lastError = 'Failed to generate test code';
          continue;
        }

        // Write test file
        const testFile = this.writeTestFile(testCode, project, moduleKey);
        console.log(`   ✓ Test file written: ${testFile}`);

        // Run the test
        const runResult = await this.runTest(testFile, project, moduleKey, containerName);

        if (runResult.success) {
          console.log(`   ✓ Test passed!`);
          // Add test file to git
          const repoDir = path.basename(project.repoPath);
          const relativeTestFile = testFile.startsWith(repoDir + '/')
            ? testFile.substring(repoDir.length + 1)
            : testFile;
          execSync(`cd ${project.repoPath} && git add ${relativeTestFile}`, { encoding: 'utf8' });

          // Amend the commit to include the test
          execSync(`cd ${project.repoPath} && git commit --amend --no-edit`, { encoding: 'utf8' });

          return { success: true, testFile: testFile, passed: true };
        } else {
          console.log(`   ✗ Test failed: ${runResult.error}`);
          lastError = runResult.error;

          // Clean up failed test file
          try {
            const fullPath = path.join(project.repoPath, testFile.startsWith(path.basename(project.repoPath) + '/')
              ? testFile.substring(path.basename(project.repoPath).length + 1)
              : testFile);
            if (fs.existsSync(fullPath)) {
              fs.unlinkSync(fullPath);
            }
          } catch (e) {
            // Ignore cleanup errors
          }
        }
      } catch (error) {
        console.log(`   ✗ Error in attempt ${attempt}: ${error.message}`);
        lastError = error.message;
      }
    }

    // All attempts failed
    console.log(`   ⚠️ Could not create passing test after 2 attempts`);
    return { success: false, testFile: null, error: lastError };
  }

  /**
   * Build prompt for test generation
   */
  buildTestPrompt(analysis, project, moduleKey, issue) {
    const sanitizedRootCause = this.sanitize(analysis.rootCause);
    const sanitizedFix = this.sanitize(analysis.fix);

    if (moduleKey === 'php') {
      return `Generate a PHPUnit test for the following fix. The test should verify the fix works correctly.

**Root Cause:** ${sanitizedRootCause}
**Fix:** ${sanitizedFix}

**Files Modified:**
${analysis.fixFiles.map(f => `- ${f.path}`).join('\n')}

**Requirements:**
- Create a focused test that specifically tests the fix (not a general test of the area)
- Do NOT use hardcoded IDs - generate test data or use existing test utilities
- Use the existing test base class (IznikTestCase or IznikAPITestCase)
- Follow the existing test patterns in the codebase
- Test should be in the appropriate test/ut/php/ subdirectory
- Include proper setUp() and tearDown() methods
- Test the specific edge case that was causing the Sentry error

**Output Format (JSON only):**
{
  "testFile": "test/ut/php/include/SomeTest.php",
  "testCode": "<?php\\n... full test file content ..."
}

CRITICAL: Output valid JSON only, no markdown.`;
    } else {
      return `Generate a Go test for the following fix. The test should verify the fix works correctly.

**Root Cause:** ${sanitizedRootCause}
**Fix:** ${sanitizedFix}

**Files Modified:**
${analysis.fixFiles.map(f => `- ${f.path}`).join('\n')}

**Requirements:**
- Create a focused test that specifically tests the fix (not a general test of the area)
- Do NOT use hardcoded IDs - generate test data dynamically
- Follow existing test patterns in the codebase
- Test the specific edge case that was causing the Sentry error
- Place test in the same package as the code being tested

**Output Format (JSON only):**
{
  "testFile": "path/to/some_test.go",
  "testCode": "package ...\\n... full test file content ..."
}

CRITICAL: Output valid JSON only, no markdown.`;
    }
  }

  /**
   * Generate test code using Claude
   */
  async generateTest(prompt, moduleKey, previousError = null) {
    if (previousError) {
      prompt += `\n\n**Previous attempt failed with error:**\n${this.sanitize(previousError)}\n\nPlease fix the test to address this error.`;
    }

    const tempPromptFile = `/tmp/sentry-test-prompt-${Date.now()}.txt`;
    fs.writeFileSync(tempPromptFile, prompt);

    try {
      const response = execSync(`claude -p "$(cat ${tempPromptFile})" --dangerously-skip-permissions`, {
        encoding: 'utf8',
        maxBuffer: 10 * 1024 * 1024,
        timeout: 300000, // 5 minutes
        cwd: process.cwd(),
      });

      fs.unlinkSync(tempPromptFile);

      // Extract JSON from response
      const jsonMatch = response.match(/```json\n([\s\S]+?)\n```/) || response.match(/\{[\s\S]+\}/);
      if (!jsonMatch) {
        throw new Error('Could not parse test generation response as JSON');
      }

      const jsonStr = jsonMatch[1] || jsonMatch[0];
      const result = JSON.parse(jsonStr);
      return result;
    } catch (error) {
      if (fs.existsSync(tempPromptFile)) {
        fs.unlinkSync(tempPromptFile);
      }
      throw error;
    }
  }

  /**
   * Write test file to disk
   */
  writeTestFile(testResult, project, moduleKey) {
    const testFile = testResult.testFile;
    const testCode = testResult.testCode;

    // Handle path - testFile might include repo dir or not
    const repoDir = path.basename(project.repoPath);
    let relativePath = testFile;
    if (testFile.startsWith(repoDir + '/')) {
      relativePath = testFile.substring(repoDir.length + 1);
    }

    const fullPath = path.join(project.repoPath, relativePath);

    // Ensure directory exists
    const dir = path.dirname(fullPath);
    if (!fs.existsSync(dir)) {
      fs.mkdirSync(dir, { recursive: true });
    }

    fs.writeFileSync(fullPath, testCode);
    return testFile;
  }

  /**
   * Run a specific test file
   */
  async runTest(testFile, project, moduleKey, containerName) {
    const repoDir = path.basename(project.repoPath);
    let relativePath = testFile;
    if (testFile.startsWith(repoDir + '/')) {
      relativePath = testFile.substring(repoDir.length + 1);
    }

    try {
      if (moduleKey === 'php') {
        // Run PHPUnit with filter for just this test file
        const testClass = path.basename(relativePath, '.php');
        const containerPath = `/var/www/iznik/${relativePath}`;

        const result = execSync(
          `docker exec freegle-apiv1 sh -c "cd /var/www/iznik && php composer/vendor/phpunit/phpunit/phpunit --configuration test/ut/php/phpunit.xml ${containerPath} 2>&1"`,
          { encoding: 'utf8', timeout: 120000 }
        );

        // Check for failures in output
        if (result.includes('FAILURES!') || result.includes('ERRORS!')) {
          return { success: false, error: result.substring(0, 500) };
        }
        return { success: true, output: result };

      } else if (moduleKey === 'go') {
        // Run go test for specific file
        const testDir = path.dirname(relativePath);
        const result = execSync(
          `docker exec freegle-apiv2 sh -c "cd /app && go test -v ./${testDir}/... -run ${path.basename(relativePath, '_test.go')} 2>&1"`,
          { encoding: 'utf8', timeout: 120000 }
        );

        if (result.includes('FAIL') || result.includes('panic')) {
          return { success: false, error: result.substring(0, 500) };
        }
        return { success: true, output: result };
      }

      return { success: false, error: 'Unknown module type' };
    } catch (error) {
      return { success: false, error: error.message.substring(0, 500) };
    }
  }

  /**
   * Create PR via GitHub CLI
   */
  async createPR(analysis, project, moduleKey, fixResult, issue, testResult = null) {
    console.log("Creating PR...");

    // Build test status section for PR body
    let testStatusSection = '';
    if (testResult) {
      if (testResult.skipped) {
        testStatusSection = `**Local Test:** ⏭️ Skipped (${testResult.reason})`;
      } else if (testResult.passed) {
        testStatusSection = `**Local Test:** ✅ Passed\n**Test File:** ${testResult.testFile}`;
      } else {
        testStatusSection = `**Local Test:** ⚠️ Could not create passing test\n**Error:** ${this.sanitize(testResult.error || 'Unknown error').substring(0, 200)}`;
      }
    } else {
      testStatusSection = '**Local Test:** Not attempted';
    }

    // Sanitize all user-facing text to prevent API keys/secrets from appearing in PRs
    const prBody = `## Automated Fix for Sentry Issue

**Root Cause:** ${this.sanitize(analysis.rootCause)}

**Changes:**
${analysis.fixFiles.map(f => {
  const changes = f.changes.map(c => `lines ${c.lines}`).join(', ');
  return `- ${f.path}: ${changes}`;
}).join('\n')}

${testStatusSection}

**Note:** This fix was generated automatically. Tests will run on CircleCI - please review results before merging.

**Sentry Issue:** [View on Sentry](${issue.permalink})

---
🤖 This PR was automatically generated by the Sentry integration system.`;

    try {
      // Push branch to remote first (required for PR creation)
      const currentBranch = execSync(`cd ${project.repoPath} && git branch --show-current`, {
        encoding: "utf8",
      }).trim();

      execSync(`cd ${project.repoPath} && git push -u origin ${currentBranch}`, {
        encoding: "utf8",
        timeout: 60000,
      });

      // Write PR body to temp file to avoid shell escaping issues
      const tempPrBodyFile = `/tmp/sentry-pr-body-${Date.now()}.txt`;
      fs.writeFileSync(tempPrBodyFile, prBody);

      const prUrl = execSync(`cd ${project.repoPath} && gh pr create --title "Fix: ${this.sanitize(analysis.rootCause).substring(0, 60)}" --body-file "${tempPrBodyFile}"`, {
        encoding: "utf8",
        timeout: 30000,
      }).trim();

      // Clean up temp file
      fs.unlinkSync(tempPrBodyFile);

      console.log(`PR created: ${prUrl}`);
      fixResult.prUrl = prUrl;

      return prUrl;
    } catch (error) {
      console.error("Failed to create PR:", error);
      throw error;
    }
  }

  /**
   * Create draft PR when tests fail
   */
  async createDraftPR(analysis, project, moduleKey, fixResult) {
    console.log("Creating draft PR (tests failed)...");

    // Sanitize all user-facing text to prevent API keys/secrets from appearing in PRs
    const prBody = `## Automated Fix Attempt for Sentry Issue (⚠️ Tests Failed)

**Root Cause:** ${this.sanitize(analysis.rootCause)}

**Attempted Changes:**
${analysis.fixFiles.map(f => {
  const changes = f.changes.map(c => `lines ${c.lines}`).join(', ');
  return `- ${f.path}: ${changes}`;
}).join('\n')}

**Test Results:** ❌ Tests failed
\`\`\`
${this.sanitize(fixResult.testOutput || fixResult.error)}
\`\`\`

**Note:** This is an automated fix attempt. The reproducing test case was created successfully,
but the proposed fix did not pass all tests. Please review and adjust.

---
🤖 This draft PR was automatically generated by the Sentry integration system.`;

    try {
      // Push branch to remote first (required for PR creation)
      const currentBranch = execSync(`cd ${project.repoPath} && git branch --show-current`, {
        encoding: "utf8",
      }).trim();

      execSync(`cd ${project.repoPath} && git push -u origin ${currentBranch}`, {
        encoding: "utf8",
        timeout: 60000,
      });

      // Write PR body to temp file to avoid shell escaping issues
      const tempPrBodyFile = `/tmp/sentry-pr-body-draft-${Date.now()}.txt`;
      fs.writeFileSync(tempPrBodyFile, prBody);

      const prUrl = execSync(`cd ${project.repoPath} && gh pr create --draft --title "[DRAFT] Fix attempt: ${this.sanitize(analysis.rootCause).substring(0, 50)}" --body-file "${tempPrBodyFile}"`, {
        encoding: "utf8",
        timeout: 30000,
      }).trim();

      // Clean up temp file
      fs.unlinkSync(tempPrBodyFile);

      console.log(`Draft PR created: ${prUrl}`);
      fixResult.prUrl = prUrl;

      return prUrl;
    } catch (error) {
      console.error("Failed to create draft PR:", error);
      throw error;
    }
  }

  /**
   * Check if issue is already being processed by another instance
   */
  async checkIfAlreadyProcessing(issueId) {
    try {
      // Fetch existing notes
      const url = `https://sentry.io/api/0/issues/${issueId}/notes/`;
      const response = await fetch(url, {
        headers: {
          'Authorization': `Bearer ${this.sentryAuthToken}`,
        },
      });

      if (!response.ok) {
        console.warn(`Could not fetch notes for issue ${issueId}: ${response.status}`);
        return false;
      }

      const notes = await response.json();

      // Add delay after Sentry API call
      await this.sleep(500);

      // Look for recent automation markers
      const automationMarker = '🤖 **Automated fix in progress**';
      const staleThresholdMinutes = 30; // Consider stale after 30 minutes

      for (const note of notes) {
        if (note.data && note.data.text && note.data.text.includes(automationMarker)) {
          // Found an automation marker - check if it's stale
          const noteDate = new Date(note.dateCreated);
          const now = new Date();
          const ageMinutes = (now - noteDate) / 1000 / 60;

          if (ageMinutes < staleThresholdMinutes) {
            // Fresh marker - another instance is processing
            return {
              fresh: true,
              timeAgo: `${Math.floor(ageMinutes)} minutes ago`,
              noteDate: note.dateCreated
            };
          } else {
            // Stale marker - previous instance probably crashed
            console.log(`Found stale automation marker (${Math.floor(ageMinutes)} minutes old) - proceeding`);
            return false;
          }
        }
      }

      return false;

    } catch (error) {
      console.error(`Error checking for existing processing: ${error.message}`);
      // If we can't check, proceed anyway to avoid blocking
      return false;
    }
  }


  /**
   * Get status of currently processing issues
   */
  getStatus() {
    const active = Array.from(this.activeProcessing.entries()).map(([id, info]) => ({
      issueId: id,
      module: info.module,
      duration: Math.floor((Date.now() - info.startTime) / 1000),
    }));

    const stats = this.getProcessedStats();

    return {
      processed: stats.total,
      processedByStatus: stats.byStatus,
      activeProcessing: active,
    };
  }

  /**
   * Get recent errors (last 10) with details
   */
  getRecentErrors() {
    const errors = this.db.prepare(`
      SELECT
        issue_id,
        module,
        title,
        error_message,
        attempts,
        last_processed_at
      FROM processed_issues
      WHERE status = 'error' OR status = 'failed'
      ORDER BY last_processed_at DESC
      LIMIT 10
    `).all();

    return errors;
  }

  /**
   * Record that a PR comment has been processed
   */
  recordProcessedComment(prNumber, commentId, commentType, commentBody, actionTaken) {
    const now = Date.now();
    this.db.prepare(`
      INSERT OR IGNORE INTO processed_pr_comments
      (pr_number, comment_id, comment_type, comment_body, processed_at, action_taken)
      VALUES (?, ?, ?, ?, ?, ?)
    `).run(prNumber, String(commentId), commentType, commentBody, now, actionTaken);
  }

  /**
   * Monitor open PRs created by this tool for comments and test failures
   */
  async monitorPRs() {
    console.log('\n=== Monitoring Open Sentry PRs ===\n');

    // Get all open PRs with Sentry autofix commits
    let query = `
      SELECT DISTINCT pr_number, pr_url, module, issue_id, title
      FROM processed_issues
      WHERE pr_number IS NOT NULL
    `;

    // Filter by specific PR number if specified
    if (this.prNumber) {
      query += ` AND pr_number = ${this.prNumber}`;
    }

    query += ` ORDER BY last_processed_at DESC`;

    const openPRs = this.db.prepare(query).all();

    if (openPRs.length === 0) {
      if (this.prNumber) {
        console.log(`No PR #${this.prNumber} found in database`);
      } else {
        console.log('No PRs with PR numbers found in database');
      }
      return;
    }

    console.log(`Found ${openPRs.length} PR(s) in database to monitor`);

    for (const prRecord of openPRs) {
      const { pr_number, pr_url, module, issue_id, title } = prRecord;
      console.log(`\nChecking PR #${pr_number} (${module})...`);

      try {
        // Extract repo info from PR URL
        const urlMatch = pr_url.match(/github\.com\/([^\/]+)\/([^\/]+)\/pull/);
        if (!urlMatch) {
          console.warn(`  ⚠ Could not parse repo from URL: ${pr_url}`);
          continue;
        }

        const [, owner, repo] = urlMatch;
        const project = this.projects[module];

        if (!project) {
          console.warn(`  ⚠ Project module "${module}" not found`);
          continue;
        }

        // Check PR status first
        const prStatusOutput = execSync(`cd ${project.repoPath} && gh pr view ${pr_number} --json state,title`, {
          encoding: 'utf8',
          timeout: 10000,
        });
        const prStatus = JSON.parse(prStatusOutput);

        if (prStatus.state !== 'OPEN') {
          console.log(`  ✓ PR is ${prStatus.state.toLowerCase()} - skipping`);
          continue;
        }

        // Check if master has moved on and merge it into the PR branch
        try {
          // Get the PR branch name
          const prBranchOutput = execSync(`cd ${project.repoPath} && gh pr view ${pr_number} --json headRefName --jq '.headRefName'`, {
            encoding: 'utf8',
            timeout: 30000,
          });
          const prBranch = prBranchOutput.trim();

          // Fetch latest master and PR branch
          execSync(`cd ${project.repoPath} && git fetch origin master ${prBranch}`, {
            encoding: 'utf8',
            timeout: 30000,
          });

          // Note: We intentionally do NOT merge master into the PR branch here
          // to keep the PR focused only on the Sentry fix changes
        } catch (mergeCheckError) {
          console.warn(`  ⚠ Could not check/merge master: ${mergeCheckError.message}`);
        }

        // Get comments on the PR
        const commentsOutput = execSync(`cd ${project.repoPath} && gh pr view ${pr_number} --json comments`, {
          encoding: 'utf8',
          timeout: 10000,
        });
        const prData = JSON.parse(commentsOutput);
        const comments = prData.comments || [];

        console.log(`  Found ${comments.length} comment(s)`);

        // Check which comments we've already processed
        for (const comment of comments) {
          const commentId = comment.id || comment.databaseId;
          const existing = this.db.prepare(
            'SELECT id FROM processed_pr_comments WHERE pr_number = ? AND comment_id = ?'
          ).get(pr_number, commentId);

          if (existing) {
            continue;
          }

          const commentBody = comment.body || '';
          const commentAuthor = comment.author?.login || 'unknown';

          // Skip comments from bot itself (starts with the bot emoji or our own response marker)
          if (commentBody.trim().startsWith('🤖') || commentBody.trim().startsWith('✅ I\'ve addressed your feedback')) {
            console.log(`  ⏭️  Skipping own comment from ${commentAuthor}`);
            this.recordProcessedComment(pr_number, commentId, 'review_comment', commentBody, 'own_comment_skipped');
            continue;
          }

          console.log(`\n  📝 New comment from ${commentAuthor}:`);
          console.log(`     "${commentBody.substring(0, 100)}${commentBody.length > 100 ? '...' : ''}"`);

          // Use Claude to analyze the comment and determine if action is needed
          await this.processPRComment(pr_number, commentId, commentBody, prRecord, project);
        }

      } catch (error) {
        console.error(`  ❌ Error monitoring PR #${pr_number}:`, error.message);
      }
    }

    console.log('\n=== PR Monitoring Complete ===\n');
  }

  /**
   * Process a PR comment to determine if a fix revision is needed
   */
  async processPRComment(prNumber, commentId, commentBody, prRecord, project) {
    console.log(`  Analyzing comment with Claude...`);

    // Sanitize issue title and reviewer comment to prevent secrets from leaking
    const sanitizedTitle = this.sanitize(prRecord.title);
    const sanitizedComment = this.sanitize(commentBody);

    const prompt = `You are analyzing a comment on a GitHub PR that was automatically created to fix a Sentry error.

**Original Issue:** ${sanitizedTitle}
**Comment from reviewer:** ${sanitizedComment}

Analyze this comment and determine:
1. Does it request changes to the fix?
2. Does it point out problems with the code?
3. What specific changes are being requested?

Respond in JSON format:
{
  "needsRevision": true/false,
  "summary": "brief summary of the feedback",
  "requestedChanges": ["change 1", "change 2", ...]
}`;

    try {
      const tempPromptFile = `/tmp/pr-comment-analysis-${Date.now()}.txt`;
      fs.writeFileSync(tempPromptFile, prompt);

      const claudeResponse = execSync(
        `cat "${tempPromptFile}" | claude --dangerously-skip-permissions --output-format text`,
        {
          encoding: 'utf8',
          timeout: 120000,
        }
      );

      fs.unlinkSync(tempPromptFile);

      console.log("Claude Code response received (with Task agent analysis)");

      // Try to parse JSON from response
      const jsonMatch = claudeResponse.match(/\{[\s\S]*\}/);
      if (!jsonMatch) {
        console.warn('  ⚠ Could not parse Claude response as JSON');
        this.recordProcessedComment(prNumber, commentId, 'review_comment', commentBody, 'skipped_unparseable');
        return;
      }

      const analysis = JSON.parse(jsonMatch[0]);
      console.log(`  Analysis: ${analysis.needsRevision ? 'Needs revision' : 'No action needed'}`);
      if (analysis.summary) {
        console.log(`  Summary: ${analysis.summary}`);
      }

      if (analysis.needsRevision && analysis.requestedChanges && analysis.requestedChanges.length > 0) {
        console.log(`  🔧 Requested changes:`);
        analysis.requestedChanges.forEach((change, i) => {
          console.log(`     ${i + 1}. ${change}`);
        });

        console.log(`  🔄 Generating revised fix based on feedback...`);
        await this.reviseFixBasedOnComment(prNumber, prRecord, project, commentBody, analysis);

        this.recordProcessedComment(prNumber, commentId, 'review_comment', commentBody, 'revision_applied');
        console.log(`  ✓ Revised fix pushed to PR`);
      } else {
        this.recordProcessedComment(prNumber, commentId, 'review_comment', commentBody, 'no_action_needed');
        console.log(`  ✓ Comment recorded - no action needed`);
      }

    } catch (error) {
      console.error(`  ❌ Error processing comment:`, error.message);

      // Don't mark comments as processed when errors occur - allow retry on next monitoring run
      // This ensures commit failures, ToS issues, and other transient errors don't prevent future retries
      console.error(`  ⚠ Comment NOT marked as processed (will retry on next run)`);
    }
  }

  /**
   * Generate and apply a revised fix based on PR comment feedback
   * Uses Task agent for all revisions (no string replacement)
   */
  async reviseFixBasedOnComment(prNumber, prRecord, project, commentBody, analysis) {
    const { module, issue_id, title } = prRecord;

    // Get PR branch name
    const prBranch = execSync(`cd ${project.repoPath} && gh pr view ${prNumber} --json headRefName --jq '.headRefName'`, {
      encoding: 'utf8',
      timeout: 30000,
    }).trim();

    console.log(`     PR branch: ${prBranch}`);

    // Checkout the PR branch to get current code
    execSync(`cd ${project.repoPath} && git fetch origin ${prBranch} && git checkout ${prBranch}`, {
      encoding: 'utf8',
      timeout: 30000,
    });

    // Get the current files in the PR
    const prFiles = execSync(`cd ${project.repoPath} && gh pr view ${prNumber} --json files --jq '.files[].path'`, {
      encoding: 'utf8',
      timeout: 30000,
    }).trim().split('\n').filter(f => f);

    console.log(`     Files in PR: ${prFiles.join(', ')}`);

    // Use Task agent to apply the reviewer's requested changes
    console.log(`     🤖 Using Task agent to apply reviewer feedback...`);

    let changesApplied = false;

    // Sanitize all data to prevent secrets from appearing in revision prompts
    const sanitizedTitle = this.sanitize(title);
    const sanitizedComment = this.sanitize(commentBody);
    const sanitizedChanges = analysis.requestedChanges.map(c => this.sanitize(c));

    try {

      const taskPrompt = `You are helping revise code in a PR based on reviewer feedback.

**Original Issue:** ${sanitizedTitle}

**Reviewer Feedback:** ${sanitizedComment}

**Requested Changes:**
${sanitizedChanges.map((c, i) => `${i + 1}. ${c}`).join('\n')}

**Files to modify:**
${prFiles.map(f => `- ${f}`).join('\n')}

**Current working directory:** ${project.repoPath}

**TASK:** Apply the reviewer's requested changes to the files listed above.
- Read each file to understand the current code
- Make the changes requested by the reviewer
- Use the Edit tool to apply the changes
- Be thorough and careful to preserve existing functionality

The PR branch (${prBranch}) is already checked out. Apply the changes directly to the files.`;

      const taskPromptFile = `/tmp/task-revision-${Date.now()}.txt`;
      fs.writeFileSync(taskPromptFile, taskPrompt);

      const taskResponse = execSync(
        `claude -p "$(cat ${taskPromptFile})" --dangerously-skip-permissions`,
        {
          encoding: 'utf8',
          maxBuffer: 20 * 1024 * 1024,
          timeout: 600000, // 10 minutes
          cwd: project.repoPath,
        }
      );

      fs.unlinkSync(taskPromptFile);

      console.log(`     ✓ Task agent completed revision`);

      // Check if any files were modified
      const gitStatus = execSync(`cd ${project.repoPath} && git status --porcelain`, { encoding: 'utf8' });
      if (gitStatus.trim()) {
        changesApplied = true;
        console.log(`     ✓ Task agent made changes to files`);
      } else {
        console.log(`     ⚠ Task agent didn't modify any files`);
      }

    } catch (taskError) {
      console.error(`     ❌ Task agent failed: ${taskError.message}`);

      // Check if this is a Claude ToS error - throw to allow retry
      const errorOutput = taskError.stdout || taskError.stderr || taskError.message || '';
      if (errorOutput.includes('Consumer Terms') || errorOutput.includes('Privacy Policy') ||
          errorOutput.includes('must run `claude`') || errorOutput.includes('review the updated terms')) {
        console.error(`     ⚠ Claude requires ToS acceptance - will retry later`);
        throw new Error('Claude requires Terms of Service acceptance');
      }
    }

    if (!changesApplied) {
      // Comment back on the PR explaining we couldn't automatically apply the changes
      // Sanitize to prevent secrets from appearing in PR comments
      const failureComment = `🤖 I attempted to automatically apply your requested changes using a Task agent, but no changes were made.

**Your feedback:** ${this.sanitize(commentBody)}

**What I understood:**
${sanitizedChanges.map((c, i) => `${i + 1}. ${c}`).join('\n')}

The Task agent was unable to apply these changes. I'll need a human to manually apply them.`;

      const tempCommentFile = `/tmp/pr-comment-${Date.now()}.txt`;
      fs.writeFileSync(tempCommentFile, failureComment);

      try {
        execSync(`cd ${project.repoPath} && gh pr comment ${prNumber} --body-file ${tempCommentFile}`, {
          encoding: 'utf8',
          timeout: 30000,
        });
        console.log(`     💬 Posted comment explaining automatic revision failed`);
      } catch (commentError) {
        console.warn(`     ⚠ Could not post comment: ${commentError.message}`);
      } finally {
        fs.unlinkSync(tempCommentFile);
      }

      throw new Error('No changes could be applied by Task agent');
    }

    // Commit and push the revision
    // Sanitize to prevent secrets from appearing in commit messages
    const commitMessage = `Address review feedback: ${this.sanitize(analysis.summary)}

${sanitizedChanges.map((c, i) => `- ${c}`).join('\n')}

🤖 This revision was automatically generated based on PR comments.`;

    // Add all modified and new files created by the Task agent
    // The Task agent may create new files (like test files) that weren't in the original PR
    execSync(`cd ${project.repoPath} && git add -A`, { encoding: 'utf8' });

    // Check if there are staged changes to commit
    const gitDiffStaged = execSync(`cd ${project.repoPath} && git diff --staged --stat`, { encoding: 'utf8' });
    if (!gitDiffStaged.trim()) {
      throw new Error('No changes to commit after applying fix');
    }

    console.log(`     Staged files:\n${gitDiffStaged}`);

    // Write commit message to file to avoid shell escaping issues with backticks and special chars
    const commitMsgFile = `/tmp/sentry-commit-msg-${Date.now()}.txt`;
    fs.writeFileSync(commitMsgFile, commitMessage);
    try {
      execSync(`cd ${project.repoPath} && git commit -F "${commitMsgFile}"`, { encoding: 'utf8' });
    } finally {
      fs.unlinkSync(commitMsgFile);
    }
    execSync(`cd ${project.repoPath} && git pull origin ${prBranch} --rebase && git push origin ${prBranch}`, { encoding: 'utf8', timeout: 60000 });

    console.log(`     ✓ Changes committed and pushed`);

    // Post a comment on the PR explaining what was done
    // Sanitize to prevent secrets from appearing in PR comments
    const prComment = `✅ I've addressed your feedback and pushed the changes.

**Your comment:** ${this.sanitize(commentBody)}

**Changes made:**
${sanitizedChanges.map((c, i) => `${i + 1}. ${c}`).join('\n')}

The updated code has been pushed to this PR. Please review when you have a chance!`;

    const tempCommentFile = `/tmp/pr-success-comment-${Date.now()}.txt`;
    fs.writeFileSync(tempCommentFile, prComment);

    try {
      execSync(`cd ${project.repoPath} && gh pr comment ${prNumber} --body-file ${tempCommentFile}`, {
        encoding: 'utf8',
        timeout: 30000,
      });
      console.log(`     💬 Posted comment on PR explaining changes`);
    } catch (commentError) {
      console.warn(`     ⚠ Could not post comment: ${commentError.message}`);
    } finally {
      fs.unlinkSync(tempCommentFile);
    }
  }
}

module.exports = SentryIntegration;

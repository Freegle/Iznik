#!/usr/bin/env node
/**
 * Exploratory Test Recorder
 *
 * Uses Playwright CDP to capture screenshots of the browser content
 * (works even when window is minimized/covered) and combines them
 * with thinking logs into a video with a sidebar.
 */

const fs = require('fs');
const path = require('path');

// Load playwright from iznik-nuxt3 where it's installed
const playwrightPath = path.join(__dirname, '..', 'iznik-nuxt3', 'node_modules', 'playwright');
const { chromium } = require(playwrightPath);
const { execSync, spawn } = require('child_process');

// Configuration
const SCREENSHOT_INTERVAL = 500; // ms between screenshots
const SIDEBAR_WIDTH = 400; // pixels for thinking sidebar
const VIDEO_FPS = 2; // 2 fps = 500ms per frame

class ExploratoryRecorder {
    constructor(outputDir) {
        this.outputDir = outputDir;
        this.screenshotsDir = path.join(outputDir, 'screenshots');
        this.browser = null;
        this.page = null;
        this.cdpSession = null;
        this.isRecording = false;
        this.screenshotCount = 0;
        this.thoughts = []; // { timestamp: ms, text: string }
        this.startTime = null;
        this.currentRoute = '/';

        // Create directories
        fs.mkdirSync(this.screenshotsDir, { recursive: true });
    }

    async start(baseUrl = 'http://freegle-dev-local.localhost') {
        console.log('[Recorder] Starting browser...');

        // Connect to existing browser or launch new one
        this.browser = await chromium.launch({
            headless: false,
            args: ['--start-maximized']
        });

        const context = await this.browser.newContext({
            viewport: { width: 1280, height: 900 }
        });

        this.page = await context.newPage();
        this.cdpSession = await this.page.context().newCDPSession(this.page);

        await this.page.goto(baseUrl);
        console.log('[Recorder] Browser ready at', baseUrl);

        return this;
    }

    async startRecording(route = '/') {
        this.currentRoute = route;
        this.isRecording = true;
        this.startTime = Date.now();
        this.screenshotCount = 0;
        this.thoughts = [];

        // Navigate to route
        const url = `http://freegle-dev-local.localhost${route}`;
        await this.page.goto(url, { waitUntil: 'networkidle' });
        console.log(`[Recorder] Recording route: ${route}`);

        // Start screenshot loop
        this._screenshotLoop();
    }

    async _screenshotLoop() {
        while (this.isRecording) {
            try {
                // Use CDP to capture screenshot (works even when window is covered)
                const { data } = await this.cdpSession.send('Page.captureScreenshot', {
                    format: 'png',
                    captureBeyondViewport: false
                });

                const filename = `frame_${String(this.screenshotCount).padStart(6, '0')}.png`;
                const filepath = path.join(this.screenshotsDir, filename);
                fs.writeFileSync(filepath, Buffer.from(data, 'base64'));

                this.screenshotCount++;
            } catch (err) {
                console.error('[Recorder] Screenshot error:', err.message);
            }

            await new Promise(r => setTimeout(r, SCREENSHOT_INTERVAL));
        }
    }

    addThought(text) {
        if (!this.isRecording || !this.startTime) return;

        const timestamp = Date.now() - this.startTime;
        this.thoughts.push({ timestamp, text });
        console.log(`[Recorder] [${(timestamp/1000).toFixed(1)}s] ${text}`);

        // Also log to file
        fs.appendFileSync(
            path.join(this.outputDir, 'thinking.log'),
            `[${(timestamp/1000).toFixed(1)}s] ${text}\n`
        );
    }

    async captureSnapshot() {
        // Capture a single screenshot and save to a known location for viewing
        try {
            const { data } = await this.cdpSession.send('Page.captureScreenshot', {
                format: 'png',
                captureBeyondViewport: false
            });

            const snapshotPath = path.join(this.outputDir, 'current-snapshot.png');
            fs.writeFileSync(snapshotPath, Buffer.from(data, 'base64'));
            console.log(`[Recorder] Snapshot saved: ${snapshotPath}`);
            return snapshotPath;
        } catch (err) {
            console.error('[Recorder] Snapshot error:', err.message);
            return null;
        }
    }

    async click(selector) {
        try {
            await this.page.click(selector);
            console.log(`[Recorder] Clicked: ${selector}`);
        } catch (err) {
            console.error(`[Recorder] Click failed on ${selector}:`, err.message);
        }
    }

    async type(selector, text) {
        try {
            await this.page.type(selector, text);
            console.log(`[Recorder] Typed into: ${selector}`);
        } catch (err) {
            console.error(`[Recorder] Type failed on ${selector}:`, err.message);
        }
    }

    async stopRecording() {
        this.isRecording = false;
        console.log(`[Recorder] Stopped. ${this.screenshotCount} screenshots captured.`);

        // Save thoughts as JSON for video generation
        fs.writeFileSync(
            path.join(this.outputDir, 'thoughts.json'),
            JSON.stringify(this.thoughts, null, 2)
        );

        // Generate video with sidebar
        await this._generateVideo();
    }

    async _generateVideo() {
        console.log('[Recorder] Generating video with thinking sidebar...');

        const routeName = this.currentRoute.replace(/[^a-zA-Z0-9]/g, '-') || 'home';
        const outputVideo = path.join(this.outputDir, `route-${routeName}.mp4`);

        // Fixed canvas size for browser area (left side)
        const CANVAS_WIDTH = 1280;
        const CANVAS_HEIGHT = 720;

        try {
            // Get first frame dimensions
            const firstFrame = path.join(this.screenshotsDir, 'frame_000000.png');
            if (!fs.existsSync(firstFrame)) {
                throw new Error('No screenshots found');
            }

            // Get actual frame dimensions using ffprobe
            let frameWidth = 1280;
            let frameHeight = 720;
            try {
                const probeResult = execSync(`ffprobe -v error -select_streams v:0 -show_entries stream=width,height -of csv=p=0 "${firstFrame}"`, { encoding: 'utf8' });
                const [w, h] = probeResult.trim().split(',').map(Number);
                if (w && h) {
                    frameWidth = w;
                    frameHeight = h;
                }
                console.log(`[Recorder] Frame dimensions: ${frameWidth}x${frameHeight}`);
            } catch (e) {
                console.log('[Recorder] Could not probe frame dimensions, using defaults');
            }

            // Calculate scale factor to fit browser in canvas while maintaining aspect ratio
            const scaleX = CANVAS_WIDTH / frameWidth;
            const scaleY = CANVAS_HEIGHT / frameHeight;
            const scaleFactor = Math.min(scaleX, scaleY, 1); // Don't upscale, only downscale

            const scaledWidth = Math.floor(frameWidth * scaleFactor);
            const scaledHeight = Math.floor(frameHeight * scaleFactor);

            // Calculate centering offsets
            const offsetX = Math.floor((CANVAS_WIDTH - scaledWidth) / 2);
            const offsetY = Math.floor((CANVAS_HEIGHT - scaledHeight) / 2);

            console.log(`[Recorder] Scaling: ${frameWidth}x${frameHeight} -> ${scaledWidth}x${scaledHeight} (factor: ${scaleFactor.toFixed(2)})`);
            console.log(`[Recorder] Centering offset: ${offsetX}x${offsetY}`);

            // Build ffmpeg drawtext filters for each thought
            let drawTextFilters = [];
            const lineHeight = 50;
            const maxLines = Math.floor((CANVAS_HEIGHT - 40) / lineHeight);

            for (let i = 0; i < this.thoughts.length; i++) {
                const thought = this.thoughts[i];
                const startTime = thought.timestamp / 1000;

                // Escape special characters for ffmpeg drawtext
                const escapedText = thought.text
                    .replace(/\\/g, '\\\\\\\\')
                    .replace(/'/g, "\\\\'")
                    .replace(/:/g, '\\:')
                    .replace(/%/g, '\\%');

                const timeLabel = `[${startTime.toFixed(1)}s]`;
                const yPos = 20 + (i % maxLines) * lineHeight;

                // Draw timestamp
                drawTextFilters.push(
                    `drawtext=text='${timeLabel}':fontsize=12:fontcolor=yellow:x=w-${SIDEBAR_WIDTH-10}:y=${yPos}:enable='gte(t,${startTime})'`
                );

                // Draw thought text (wrapped if needed)
                const wrappedText = escapedText.substring(0, 45) + (escapedText.length > 45 ? '...' : '');
                drawTextFilters.push(
                    `drawtext=text='${wrappedText}':fontsize=12:fontcolor=white:x=w-${SIDEBAR_WIDTH-60}:y=${yPos}:enable='gte(t,${startTime})'`
                );
            }

            // Build the complete filter chain:
            // 1. Scale browser to fit in canvas (maintaining aspect ratio)
            // 2. Pad to fixed canvas size, centering the content
            // 3. Add sidebar space on the right
            // 4. Add drawtext filters for thoughts
            // 5. Ensure dimensions are divisible by 2
            let filters = [];

            // Scale to fit (only if needed)
            if (scaleFactor < 1) {
                filters.push(`scale=${scaledWidth}:${scaledHeight}`);
            }

            // Pad to canvas size with dark background, centering the content
            filters.push(`pad=${CANVAS_WIDTH}:${CANVAS_HEIGHT}:${offsetX}:${offsetY}:color=0x1a1a2e`);

            // Add sidebar
            filters.push(`pad=iw+${SIDEBAR_WIDTH}:ih:0:0:color=0x1a1a2e`);

            // Add thought text
            filters.push(...drawTextFilters);

            // Ensure even dimensions
            filters.push('scale=trunc(iw/2)*2:trunc(ih/2)*2');

            const finalFilter = filters.join(',');

            const totalWidth = CANVAS_WIDTH + SIDEBAR_WIDTH;
            console.log('[Recorder] Converting frames to video with sidebar...');
            console.log(`[Recorder] Output dimensions: ${totalWidth}x${CANVAS_HEIGHT}`);

            const ffmpegCmd = `ffmpeg -y -framerate ${VIDEO_FPS} -i "${this.screenshotsDir}/frame_%06d.png" -vf "${finalFilter}" -c:v libx264 -pix_fmt yuv420p -preset fast -profile:v baseline -level 3.0 -movflags +faststart "${outputVideo}"`;

            execSync(ffmpegCmd, { stdio: 'inherit' });

            console.log(`[Recorder] Video saved: ${outputVideo}`);
            console.log(`[Recorder] Thinking log: ${path.join(this.outputDir, 'thinking.log')}`);

        } catch (err) {
            console.error('[Recorder] Video generation error:', err.message);
            console.error(err.stack);
        }
    }

    async close() {
        if (this.browser) {
            await this.browser.close();
        }
    }
}

// CLI interface
async function main() {
    const args = process.argv.slice(2);
    const command = args[0];

    // State file for inter-process communication
    const stateFile = '/tmp/exploratory-recorder.json';

    switch (command) {
        case 'start': {
            const outputDir = args[1] || `/tmp/exploratory-${Date.now()}`;
            const recorder = new ExploratoryRecorder(outputDir);
            await recorder.start();

            // Save state
            fs.writeFileSync(stateFile, JSON.stringify({
                outputDir,
                pid: process.pid
            }));

            console.log(`[Recorder] Ready. Use 'route', 'think', 'stop' commands.`);
            console.log(`[Recorder] Output: ${outputDir}`);

            // Keep running
            process.on('SIGINT', async () => {
                await recorder.close();
                process.exit(0);
            });

            // Read commands from stdin
            const readline = require('readline');
            const rl = readline.createInterface({ input: process.stdin, output: process.stdout });

            rl.on('line', async (line) => {
                const [cmd, ...rest] = line.trim().split(' ');
                const arg = rest.join(' ');

                switch (cmd) {
                    case 'route':
                        await recorder.startRecording(arg || '/');
                        break;
                    case 'think':
                        recorder.addThought(arg);
                        break;
                    case 'snap':
                    case 'snapshot':
                        await recorder.captureSnapshot();
                        break;
                    case 'click':
                        await recorder.click(arg);
                        break;
                    case 'type':
                        // Format: type selector|text
                        const [selector, ...textParts] = arg.split('|');
                        await recorder.type(selector.trim(), textParts.join('|').trim());
                        break;
                    case 'wait':
                        const ms = parseInt(arg) || 1000;
                        await new Promise(r => setTimeout(r, ms));
                        console.log(`[Recorder] Waited ${ms}ms`);
                        break;
                    case 'stop':
                        await recorder.stopRecording();
                        await recorder.close();
                        process.exit(0);
                        break;
                    default:
                        console.log('Unknown command:', cmd);
                }
            });
            break;
        }

        case 'generate-video': {
            // Generate video from pre-collected screenshots and thoughts.json
            const dir = args[1] || '.';
            const screenshotsDir = path.join(dir, 'screenshots');
            const thoughtsFile = path.join(dir, 'thoughts.json');
            const outputVideo = path.join(dir, 'exploratory-test.mp4');

            if (!fs.existsSync(screenshotsDir)) {
                console.error('Screenshots directory not found:', screenshotsDir);
                process.exit(1);
            }

            let thoughts = [];
            if (fs.existsSync(thoughtsFile)) {
                thoughts = JSON.parse(fs.readFileSync(thoughtsFile, 'utf8'));
            }

            console.log(`[Generator] Found ${thoughts.length} thoughts`);
            console.log(`[Generator] Generating video from ${screenshotsDir}`);

            // Use the same video generation logic
            const CANVAS_WIDTH = 1280;
            const CANVAS_HEIGHT = 720;

            try {
                const firstFrame = path.join(screenshotsDir, 'frame_000000.png');
                if (!fs.existsSync(firstFrame)) {
                    throw new Error('No screenshots found');
                }

                let frameWidth = 1280, frameHeight = 720;
                try {
                    const probeResult = execSync(`ffprobe -v error -select_streams v:0 -show_entries stream=width,height -of csv=p=0 "${firstFrame}"`, { encoding: 'utf8' });
                    const [w, h] = probeResult.trim().split(',').map(Number);
                    if (w && h) { frameWidth = w; frameHeight = h; }
                } catch (e) {}

                const scaleX = CANVAS_WIDTH / frameWidth;
                const scaleY = CANVAS_HEIGHT / frameHeight;
                const scaleFactor = Math.min(scaleX, scaleY, 1);
                const scaledWidth = Math.floor(frameWidth * scaleFactor);
                const scaledHeight = Math.floor(frameHeight * scaleFactor);
                const offsetX = Math.floor((CANVAS_WIDTH - scaledWidth) / 2);
                const offsetY = Math.floor((CANVAS_HEIGHT - scaledHeight) / 2);

                let drawTextFilters = [];
                const lineHeight = 50;
                const maxLines = Math.floor((CANVAS_HEIGHT - 40) / lineHeight);

                for (let i = 0; i < thoughts.length; i++) {
                    const thought = thoughts[i];
                    const startTime = thought.timestamp / 1000;
                    const escapedText = thought.text
                        .replace(/\\/g, '\\\\\\\\')
                        .replace(/'/g, "\\\\'")
                        .replace(/:/g, '\\:')
                        .replace(/%/g, '\\%');

                    const timeLabel = `[${startTime.toFixed(1)}s]`;
                    const yPos = 20 + (i % maxLines) * lineHeight;

                    drawTextFilters.push(
                        `drawtext=text='${timeLabel}':fontsize=12:fontcolor=yellow:x=w-390:y=${yPos}:enable='gte(t,${startTime})'`
                    );
                    const wrappedText = escapedText.substring(0, 45) + (escapedText.length > 45 ? '...' : '');
                    drawTextFilters.push(
                        `drawtext=text='${wrappedText}':fontsize=12:fontcolor=white:x=w-340:y=${yPos}:enable='gte(t,${startTime})'`
                    );
                }

                let filters = [];
                if (scaleFactor < 1) {
                    filters.push(`scale=${scaledWidth}:${scaledHeight}`);
                }
                filters.push(`pad=${CANVAS_WIDTH}:${CANVAS_HEIGHT}:${offsetX}:${offsetY}:color=0x1a1a2e`);
                filters.push(`pad=iw+400:ih:0:0:color=0x1a1a2e`);
                filters.push(...drawTextFilters);
                filters.push('scale=trunc(iw/2)*2:trunc(ih/2)*2');

                const finalFilter = filters.join(',');
                const ffmpegCmd = `ffmpeg -y -framerate 2 -i "${screenshotsDir}/frame_%06d.png" -vf "${finalFilter}" -c:v libx264 -pix_fmt yuv420p -preset fast -profile:v baseline -level 3.0 -movflags +faststart "${outputVideo}"`;

                execSync(ffmpegCmd, { stdio: 'inherit' });
                console.log(`[Generator] Video saved: ${outputVideo}`);

            } catch (err) {
                console.error('[Generator] Error:', err.message);
            }
            break;
        }

        default:
            console.log(`
Exploratory Test Recorder

Usage:
  node exploratory-recorder.js start [output-dir]
  node exploratory-recorder.js generate-video [output-dir]

Interactive commands (after start):
  route /path    - Navigate and start recording
  think "text"   - Add a thinking observation
  snap           - Capture a snapshot
  click selector - Click an element
  type sel|text  - Type into an element
  wait ms        - Wait milliseconds
  stop           - Stop recording and generate video

For generate-video:
  Expects screenshots/ folder with frame_NNNNNN.png files
  Optionally reads thoughts.json for sidebar text
`);
    }
}

main().catch(console.error);

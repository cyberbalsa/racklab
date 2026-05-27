const { existsSync } = require('node:fs');

const chromeCandidates = [
    process.env.PA11Y_CHROME_PATH,
    process.env.PUPPETEER_EXECUTABLE_PATH,
    '/usr/bin/chromium-browser',
    '/usr/bin/chromium',
    '/usr/bin/google-chrome',
    '/usr/bin/google-chrome-stable',
].filter(Boolean);

const executablePath = chromeCandidates.find((candidate) => existsSync(candidate));

module.exports = {
    defaults: {
        chromeLaunchConfig: {
            ...(executablePath ? { executablePath } : {}),
            args: [
                '--no-sandbox',
                '--disable-dev-shm-usage',
            ],
        },
        standard: 'WCAG2AA',
        timeout: 30000,
    },
    urls: [
        'http://127.0.0.1:8000/',
        'http://127.0.0.1:8000/hello',
    ],
};

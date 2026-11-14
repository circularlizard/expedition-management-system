const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const desktopViewport = { width: 1280, height: 800 };
const mobileViewport = { width: 375, height: 667 };

const pagesToAudit = [
  { name: 'expeditions_board', url: 'https://localhost/wp-admin/admin.php?page=ems' },
  { name: 'explorers_list', url: 'https://localhost/wp-admin/admin.php?page=ems-explorers' },
  { name: 'participant_places', url: 'https://localhost/wp-admin/admin.php?page=ems-participant-signups' },
  { name: 'expedition_signups', url: 'https://localhost/wp-admin/admin.php?page=ems-expedition-signups' },
  { name: 'volunteers', url: 'https://localhost/wp-admin/admin.php?page=ems-volunteers' },
  { name: 'osm_sync_explorers', url: 'https://localhost/wp-admin/admin.php?page=ems-reference&tab=explorers' },
  { name: 'osm_sync_patrols', url: 'https://localhost/wp-admin/admin.php?page=ems-reference&tab=patrols' },
  { name: 'osm_sync_events', url: 'https://localhost/wp-admin/admin.php?page=ems-reference&tab=events' },
  { name: 'osm_sync_diagnostics', url: 'https://localhost/wp-admin/admin.php?page=ems-reference&tab=diagnostics' },
  { name: 'osm_sync_pushback', url: 'https://localhost/wp-admin/admin.php?page=ems-reference&tab=pushback' },
  { name: 'column_mapper', url: 'https://localhost/wp-admin/admin.php?page=ems-column-mapper' }
];

async function captureScreenshots(page, viewName, viewport, dirSuffix) {
  const dirPath = path.join(__dirname, `../tests/ui-audit/screenshots/${dirSuffix}`);
  if (!fs.existsSync(dirPath)) {
    fs.mkdirSync(dirPath, { recursive: true });
  }

  await page.setViewportSize(viewport);
  // Wait a short time for any layout recalculations
  await page.waitForTimeout(2000);
  
  const screenshotPath = path.join(dirPath, `${viewName}.png`);
  await page.screenshot({ path: screenshotPath, fullPage: true });
  console.log(`Saved screenshot: ${screenshotPath}`);
}

(async () => {
  console.log('Launching browser...');
  const browser = await chromium.launch({
    headless: true,
    args: ['--ignore-certificate-errors']
  });

  const context = await browser.newContext({
    ignoreHTTPSErrors: true
  });
  
  const page = await context.newPage();

  console.log('Navigating to login page...');
  await page.goto('https://localhost/wp-login.php');

  console.log('Logging in...');
  await page.fill('#user_login', 'admin');
  await page.fill('#user_pass', 'password');
  await page.click('#wp-submit');
  
  // Wait for login to complete and redirect
  await page.waitForURL(/wp-admin/);
  console.log('Logged in successfully!');

  for (const target of pagesToAudit) {
    console.log(`Navigating to ${target.name}: ${target.url}...`);
    try {
      await page.goto(target.url);
      
      // Wait for content wrapper and give React a moment to render
      await page.waitForSelector('.wrap', { timeout: 10000 });
      await page.waitForTimeout(3000); // Give extra time for React app/AJAX loads

      // Capture Desktop
      await captureScreenshots(page, target.name, desktopViewport, 'desktop');

      // Capture Mobile
      await captureScreenshots(page, target.name, mobileViewport, 'mobile');

    } catch (e) {
      console.error(`Error processing ${target.name}:`, e.message);
    }
  }

  await browser.close();
  console.log('UI Audit traversal complete.');
})();

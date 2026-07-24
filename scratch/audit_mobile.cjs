const { chromium, devices } = require('playwright');
const fs = require('fs');

async function run() {
  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext({
    ...devices['iPhone 13'],
    viewport: { width: 390, height: 844 },
    recordVideo: { dir: 'scratch/mobile_videos' }
  });
  const page = await context.newPage();

  // Test 1: Login Page Password Toggle
  await page.goto('http://127.0.0.1:8000/login');
  await page.fill('input[name="email"]', 'admin@sims.com');
  await page.fill('input[name="password"]', 'password');
  
  // Find the viewable toggle (eye icon)
  const passwordInput = page.locator('input[name="password"]');
  // In flux:input viewable, there's usually a button as a sibling or parent
  const toggleBtn = passwordInput.locator('xpath=following-sibling::button | ../button | ../../button').first();
  if (await toggleBtn.count() > 0) {
      await toggleBtn.click({ force: true });
      await page.waitForTimeout(500);
      const type = await passwordInput.getAttribute('type');
      console.log('Password visibility toggle clicked. Type is now:', type);
  } else {
      console.log('Could not find password toggle button.');
  }

  await page.screenshot({ path: 'scratch/mobile_login_page.png' });

  // Login
  await page.click('button[type="submit"]');
  await page.waitForURL('**/dashboard');
  
  // Test 2: Sidebar Toggle
  console.log('Testing Sidebar Toggle...');
  const sidebarToggle = page.locator('button').filter({ has: page.locator('svg') }).filter({ hasText: '' }).first(); // Assuming flux:sidebar.toggle renders a button
  // Actually, flux:sidebar.toggle renders a button with data-flux-sidebar-toggle or similar
  const toggle = page.locator('[data-flux-sidebar-toggle], flux-sidebar-toggle button, button:has(svg.lucide-bars-2), button:has(svg.lucide-menu)').first();
  if (await toggle.count() > 0) {
      // Try to click it
      try {
          await toggle.click({ timeout: 2000 });
          console.log('Sidebar toggle clicked successfully.');
          await page.waitForTimeout(500);
          await page.screenshot({ path: 'scratch/mobile_sidebar_open.png' });
      } catch (e) {
          console.log('Sidebar toggle click failed:', e.message);
      }
  } else {
      console.log('Could not find sidebar toggle button.');
  }

  await browser.close();
}

run().catch(console.error);

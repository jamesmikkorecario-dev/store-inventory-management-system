const { chromium, devices } = require('playwright');
const fs = require('fs');

async function run() {
  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext({
    ...devices['iPhone 13'],
    viewport: { width: 390, height: 844 },
    recordVideo: { dir: 'scratch/mobile_videos_full' }
  });
  
  const page = await context.newPage();
  const report = [];

  const logIssue = (area, issue, error) => {
      report.push({ area, issue, error: error ? error.message.split('\n')[0] : 'Unknown' });
      console.error(`[ISSUE] ${area}: ${issue} -> ${error ? error.message.split('\n')[0] : ''}`);
  };

  try {
      // 1. Authentication
      console.log('Testing Authentication on Mobile...');
      await page.goto('http://127.0.0.1:8000/login');
      
      // Test password visibility
      try {
          const passwordToggle = page.locator('button[aria-label="Show password"], button:has(svg.lucide-eye)').first();
          if (await passwordToggle.count() > 0) {
              await passwordToggle.click({ timeout: 2000 });
              await page.waitForTimeout(300);
          }
      } catch (e) {
          logIssue('Authentication', 'Password visibility toggle not clickable on mobile', e);
      }

      await page.fill('input[name="email"]', 'admin@sims.com');
      await page.fill('input[name="password"]', 'password');
      await page.click('button[type="submit"]');
      await page.waitForURL('**/dashboard', { timeout: 5000 });
      console.log('Login successful.');

      // 2. Navigation & Sidebar
      console.log('Testing Sidebar & Navigation...');
      const sidebarToggle = page.locator('flux-header button, [data-flux-sidebar-toggle]').first();
      
      try {
          await sidebarToggle.click({ timeout: 2000 });
          await page.waitForTimeout(500);
      } catch (e) {
          logIssue('Navigation', 'Sidebar toggle button not clickable', e);
      }

      // Try closing sidebar by clicking outside (backdrop) or close button
      try {
          const backdrop = page.locator('[data-flux-sidebar-backdrop]').first();
          if (await backdrop.count() > 0) {
              await backdrop.click({ timeout: 2000, position: { x: 380, y: 100 } });
              await page.waitForTimeout(500);
          }
      } catch (e) {
          logIssue('Navigation', 'Cannot close sidebar on mobile', e);
      }

      // 3. Products Page
      console.log('Testing Products Page...');
      await page.goto('http://127.0.0.1:8000/products');
      await page.waitForTimeout(1000);
      
      // Check tables horizontal scroll
      const tableWrapper = page.locator('.overflow-x-auto').first();
      if (await tableWrapper.count() > 0) {
          const boundingBox = await tableWrapper.boundingBox();
          const evaluateScroll = await tableWrapper.evaluate(el => el.scrollWidth > el.clientWidth);
          if (evaluateScroll) {
              console.log('Table has horizontal scroll, which is correct for mobile.');
          } else {
              logIssue('Products', 'Table might not be horizontally scrollable or is squished');
          }
      }

      // Test Create Product Modal
      try {
          const createBtn = page.locator('button:has-text("Add Product")').first();
          if (await createBtn.count() > 0) {
              await createBtn.click({ timeout: 2000 });
              await page.waitForTimeout(500);
              
              // Try clicking inside modal
              const modalSave = page.locator('dialog[open] button:has-text("Save")').first();
              if (await modalSave.count() > 0) {
                  await modalSave.click({ timeout: 2000 });
              }
              // Press Escape to close
              await page.keyboard.press('Escape');
          }
      } catch (e) {
          logIssue('Products', 'Add Product modal interactions failing', e);
      }

      // Test Table Dropdown Actions
      try {
          const actionBtn = page.locator('td button:has(svg)').first();
          if (await actionBtn.count() > 0) {
              await actionBtn.click({ timeout: 2000 });
              await page.waitForTimeout(500);
              // close dropdown
              await page.keyboard.press('Escape');
          }
      } catch (e) {
          logIssue('Products', 'Table action dropdowns not clickable (z-index or overlay issue)', e);
      }
      
      // 4. Transactions Page
      console.log('Testing Transactions Page...');
      await page.goto('http://127.0.0.1:8000/transactions');
      await page.waitForTimeout(1000);

      try {
          const logBtn = page.locator('button:has-text("Log Movement")').first();
          if (await logBtn.count() > 0) {
              await logBtn.click({ timeout: 2000 });
              await page.waitForTimeout(500);
              
              // Interact with combobox
              const combobox = page.locator('input[placeholder="Search and select a product..."]').first();
              if (await combobox.count() > 0) {
                  await combobox.click({ timeout: 2000 });
                  await page.waitForTimeout(300);
                  await combobox.fill('prod');
                  await page.waitForTimeout(500);
              }
              await page.keyboard.press('Escape'); // close combobox
              await page.keyboard.press('Escape'); // close modal
          }
      } catch (e) {
          logIssue('Transactions', 'Log Movement modal or Combobox failing on mobile', e);
      }

      // 5. Check horizontal overflow on body
      const bodyWidth = await page.evaluate(() => document.body.scrollWidth);
      const windowWidth = await page.evaluate(() => window.innerWidth);
      if (bodyWidth > windowWidth) {
          logIssue('Global', `Body has horizontal overflow (scrollWidth: ${bodyWidth}, innerWidth: ${windowWidth})`);
      }

  } catch (e) {
      console.error('Fatal error during tests:', e);
  } finally {
      fs.writeFileSync('scratch/mobile_audit_report.json', JSON.stringify(report, null, 2));
      console.log('Audit complete. Report saved.');
      await browser.close();
  }
}

run().catch(console.error);

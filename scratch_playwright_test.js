import { chromium } from 'playwright';
import { execSync } from 'child_process';
import fs from 'fs';
import path from 'path';

const BASE_URL = 'http://127.0.0.1:8000';
const SCREENSHOT_DIR = '/home/james/.gemini/antigravity-cli/brain/f911e69b-acc6-4798-ab23-1a2d8930b952/scratch/screenshots';

// Ensure screenshot directory exists
if (!fs.existsSync(SCREENSHOT_DIR)) {
    fs.mkdirSync(SCREENSHOT_DIR, { recursive: true });
}

// Helper to run PHP code via Artisan Tinker
function checkDatabase(phpCode) {
    try {
        const escapedCode = phpCode.replace(/"/g, '\\"').replace(/\$/g, '\\$');
        const output = execSync(`php artisan tinker --execute="${escapedCode}"`, { encoding: 'utf8' });
        return output.trim();
    } catch (e) {
        return `ERROR: ${e.message}`;
    }
}

async function runAudit() {
    console.log('Launching browser...');
    const browser = await chromium.launch({ headless: true });
    const context = await browser.newContext({
        viewport: { width: 1280, height: 800 }
    });
    const page = await context.newPage();

    const consoleErrors = [];
    const networkFailures = [];

    // Capture console errors
    page.on('console', msg => {
        if (msg.type() === 'error') {
            consoleErrors.push(msg.text());
        }
    });

    // Capture network failures
    page.on('requestfailed', request => {
        networkFailures.push(`${request.url()}: ${request.failure().errorText}`);
    });

    const report = {
        timestamp: new Date().toISOString(),
        consoleErrors,
        networkFailures,
        modules: {}
    };

    // Generate unique random strings to prevent duplicate validation conflicts
    const randomId = Date.now();
    const userEmail = `user_${randomId}@test.com`;
    const userName = `Browser User ${randomId}`;
    const userEditName = `Browser User ${randomId} Edited`;

    const supplierEmail = `supplier_${randomId}@test.com`;
    const supplierName = `Supplier ${randomId}`;
    const supplierEditName = `Supplier ${randomId} Edited`;

    const categoryName = `Category ${randomId}`;
    const categoryDesc = `Integrated chips ${randomId}`;
    const categoryEditDesc = `Integrated chips ${randomId} Edited`;

    const productSku = `SKU-${randomId}`;
    const productName = `Product ${randomId}`;
    const productEditName = `Product ${randomId} Edited`;

    try {
        // --- 0. Login ---
        console.log('Logging in...');
        await page.goto(`${BASE_URL}/login`);
        await page.fill('input[type="email"]', 'admin@sims.com');
        await page.fill('input[type="password"]', 'password');
        await page.screenshot({ path: path.join(SCREENSHOT_DIR, 'login_before.png') });
        await page.click('button[type="submit"]');
        await page.waitForURL(`${BASE_URL}/dashboard`);
        await page.screenshot({ path: path.join(SCREENSHOT_DIR, 'login_after.png') });
        console.log('Logged in successfully.');

        // --- 1. Users Module ---
        console.log('Auditing Users Module...');
        report.modules.Users = {};
        await page.goto(`${BASE_URL}/users`);
        await page.waitForLoadState('networkidle');
        await page.screenshot({ path: path.join(SCREENSHOT_DIR, 'users_list_before.png') });

        // Create User
        await page.click('button:has-text("Add User")');
        await page.waitForTimeout(500); // Wait for modal animation
        await page.fill('input[placeholder="Full Name"]', userName);
        await page.fill('input[placeholder="email@example.com"]', userEmail);
        await page.fill('input[placeholder="Min. 8 characters"]', 'password123');
        await page.selectOption('select[wire\\:model\\.live="roleName"]', 'Staff');
        await page.selectOption('select[wire\\:model="status"]', 'active');
        await page.screenshot({ path: path.join(SCREENSHOT_DIR, 'users_create_before_submit.png') });
        await page.click('button:has-text("Save Changes")');
        await page.waitForTimeout(1000); // Wait for Livewire refresh and toast
        await page.screenshot({ path: path.join(SCREENSHOT_DIR, 'users_create_after_submit.png') });

        const dbUserCreated = checkDatabase(`echo App\\Models\\User::where("email", "${userEmail}")->exists() ? "YES" : "NO";`);
        report.modules.Users.Create = {
            selector: 'button:has-text("Add User") & Save Changes',
            livewireAction: 'saveUser()',
            dbChange: `User found: ${dbUserCreated}`,
            status: dbUserCreated === 'YES' ? 'PASS' : 'FAIL'
        };

        if (dbUserCreated === 'YES') {
            const userId = checkDatabase(`echo App\\Models\\User::where("email", "${userEmail}")->value("id");`);
            
            // Edit User
            await page.click(`button[wire\\:click^="openEditModal(${userId})"]`);
            await page.waitForTimeout(500);
            await page.fill('input[placeholder="Full Name"]', userEditName);
            await page.screenshot({ path: path.join(SCREENSHOT_DIR, 'users_edit_before_submit.png') });
            await page.click('button:has-text("Save Changes")');
            await page.waitForTimeout(1000);
            await page.screenshot({ path: path.join(SCREENSHOT_DIR, 'users_edit_after_submit.png') });

            const dbUserUpdated = checkDatabase(`echo App\\Models\\User::where("email", "${userEmail}")->value("name");`);
            report.modules.Users.Edit = {
                selector: `button[wire\\:click^="openEditModal(${userId})"]`,
                livewireAction: 'saveUser()',
                dbChange: `Updated Name: ${dbUserUpdated}`,
                status: dbUserUpdated === userEditName ? 'PASS' : 'FAIL'
            };

            // Delete User
            await page.click(`button[wire\\:click^="confirmDelete(${userId})"]`);
            await page.waitForTimeout(500);
            await page.screenshot({ path: path.join(SCREENSHOT_DIR, 'users_delete_before_confirm.png') });
            await page.click('button:has-text("Delete User")');
            await page.waitForTimeout(1000);
            await page.screenshot({ path: path.join(SCREENSHOT_DIR, 'users_delete_after_confirm.png') });

            const dbUserDeleted = checkDatabase(`echo App\\Models\\User::find(${userId}) ? "EXISTS" : "SOFT_DELETED";`);
            report.modules.Users.Delete = {
                selector: `button[wire\\:click^="confirmDelete(${userId})"]`,
                livewireAction: 'deleteUser()',
                dbChange: `Lookup: ${dbUserDeleted}`,
                status: dbUserDeleted === 'SOFT_DELETED' ? 'PASS' : 'FAIL'
            };
        } else {
            console.log('ERROR: Attempt to find "Browser Test User" in database failed');
            report.modules.Users.Edit = { status: 'FAIL', reason: 'User not created' };
            report.modules.Users.Delete = { status: 'FAIL', reason: 'User not created' };
        }

        // --- 2. Suppliers Module ---
        console.log('Auditing Suppliers Module...');
        report.modules.Suppliers = {};
        await page.goto(`${BASE_URL}/suppliers`);
        await page.waitForLoadState('networkidle');
        await page.screenshot({ path: path.join(SCREENSHOT_DIR, 'suppliers_list_before.png') });

        // Create Supplier
        await page.click('button:has-text("Add Supplier")');
        await page.waitForTimeout(500);
        await page.fill('input[placeholder="Apex Logistics LLC"]', supplierName);
        await page.fill('input[placeholder="John Doe"]', 'Contact Person');
        await page.fill('input[placeholder="sales@supplier.com"]', supplierEmail);
        await page.fill('input[placeholder="+1 (555) 123-4567"]', '999999');
        await page.fill('textarea[placeholder="Street, City, Zip Code"]', 'San Francisco');
        await page.selectOption('select[wire\\:model="status"]', 'active');
        await page.screenshot({ path: path.join(SCREENSHOT_DIR, 'suppliers_create_before_submit.png') });
        await page.click('button:has-text("Save Changes")');
        await page.waitForTimeout(1000);
        await page.screenshot({ path: path.join(SCREENSHOT_DIR, 'suppliers_create_after_submit.png') });

        const dbSupplierCreated = checkDatabase(`echo App\\Models\\Supplier::where("email", "${supplierEmail}")->exists() ? "YES" : "NO";`);
        report.modules.Suppliers.Create = {
            selector: 'button:has-text("Add Supplier") & Save Changes',
            livewireAction: 'saveSupplier()',
            dbChange: `Supplier found: ${dbSupplierCreated}`,
            status: dbSupplierCreated === 'YES' ? 'PASS' : 'FAIL'
        };

        if (dbSupplierCreated === 'YES') {
            const supplierId = checkDatabase(`echo App\\Models\\Supplier::where("email", "${supplierEmail}")->value("id");`);

            // Edit Supplier
            await page.click(`button[wire\\:click^="openEditModal(${supplierId})"]`);
            await page.waitForTimeout(500);
            await page.fill('input[placeholder="Apex Logistics LLC"]', supplierEditName);
            await page.screenshot({ path: path.join(SCREENSHOT_DIR, 'suppliers_edit_before_submit.png') });
            await page.click('button:has-text("Save Changes")');
            await page.waitForTimeout(1000);
            await page.screenshot({ path: path.join(SCREENSHOT_DIR, 'suppliers_edit_after_submit.png') });

            const dbSupplierUpdated = checkDatabase(`echo App\\Models\\Supplier::where("email", "${supplierEmail}")->value("name");`);
            report.modules.Suppliers.Edit = {
                selector: `button[wire\\:click^="openEditModal(${supplierId})"]`,
                livewireAction: 'saveSupplier()',
                dbChange: `Updated Name: ${dbSupplierUpdated}`,
                status: dbSupplierUpdated === supplierEditName ? 'PASS' : 'FAIL'
            };

            // Delete Supplier
            await page.click(`button[wire\\:click^="confirmDelete(${supplierId})"]`);
            await page.waitForTimeout(500);
            await page.screenshot({ path: path.join(SCREENSHOT_DIR, 'suppliers_delete_before_confirm.png') });
            await page.click('button:has-text("Delete Supplier")');
            await page.waitForTimeout(1000);
            await page.screenshot({ path: path.join(SCREENSHOT_DIR, 'suppliers_delete_after_confirm.png') });

            const dbSupplierDeleted = checkDatabase(`echo App\\Models\\Supplier::find(${supplierId}) ? "EXISTS" : "SOFT_DELETED";`);
            report.modules.Suppliers.Delete = {
                selector: `button[wire\\:click^="confirmDelete(${supplierId})"]`,
                livewireAction: 'deleteSupplier()',
                dbChange: `Lookup: ${dbSupplierDeleted}`,
                status: dbSupplierDeleted === 'SOFT_DELETED' ? 'PASS' : 'FAIL'
            };
        } else {
            console.log('ERROR: Attempt to find "Browser Test Supplier" in database failed');
            report.modules.Suppliers.Edit = { status: 'FAIL', reason: 'Supplier not created' };
            report.modules.Suppliers.Delete = { status: 'FAIL', reason: 'Supplier not created' };
        }

        // --- 3. Categories Module ---
        console.log('Auditing Categories Module...');
        report.modules.Categories = {};
        await page.goto(`${BASE_URL}/categories`);
        await page.waitForLoadState('networkidle');
        await page.screenshot({ path: path.join(SCREENSHOT_DIR, 'categories_list_before.png') });

        // Create Category
        await page.click('button:has-text("Add Category")');
        await page.waitForTimeout(500);
        await page.fill('input[placeholder="Electronics, Stationery"]', categoryName);
        await page.fill('textarea[placeholder="Optional description detailing what products belong to this category"]', categoryDesc);
        await page.screenshot({ path: path.join(SCREENSHOT_DIR, 'categories_create_before_submit.png') });
        await page.click('button:has-text("Save Changes")');
        await page.waitForTimeout(1000);
        await page.screenshot({ path: path.join(SCREENSHOT_DIR, 'categories_create_after_submit.png') });

        const dbCategoryCreated = checkDatabase(`echo App\\Models\\Category::where("name", "${categoryName}")->exists() ? "YES" : "NO";`);
        report.modules.Categories.Create = {
            selector: 'button:has-text("Add Category") & Save Changes',
            livewireAction: 'saveCategory()',
            dbChange: `Category found: ${dbCategoryCreated}`,
            status: dbCategoryCreated === 'YES' ? 'PASS' : 'FAIL'
        };

        if (dbCategoryCreated === 'YES') {
            const categoryId = checkDatabase(`echo App\\Models\\Category::where("name", "${categoryName}")->value("id");`);

            // Edit Category
            await page.click(`button[wire\\:click^="openEditModal(${categoryId})"]`);
            await page.waitForTimeout(500);
            await page.fill('textarea[placeholder="Optional description detailing what products belong to this category"]', categoryEditDesc);
            await page.screenshot({ path: path.join(SCREENSHOT_DIR, 'categories_edit_before_submit.png') });
            await page.click('button:has-text("Save Changes")');
            await page.waitForTimeout(1000);
            await page.screenshot({ path: path.join(SCREENSHOT_DIR, 'categories_edit_after_submit.png') });

            const dbCategoryUpdated = checkDatabase(`echo App\\Models\\Category::where("name", "${categoryName}")->value("description");`);
            report.modules.Categories.Edit = {
                selector: `button[wire\\:click^="openEditModal(${categoryId})"]`,
                livewireAction: 'saveCategory()',
                dbChange: `Updated Desc: ${dbCategoryUpdated}`,
                status: dbCategoryUpdated === categoryEditDesc ? 'PASS' : 'FAIL'
            };

            // Delete Category
            await page.click(`button[wire\\:click^="confirmDelete(${categoryId})"]`);
            await page.waitForTimeout(500);
            await page.screenshot({ path: path.join(SCREENSHOT_DIR, 'categories_delete_before_confirm.png') });
            await page.click('button:has-text("Delete Category")');
            await page.waitForTimeout(1000);
            await page.screenshot({ path: path.join(SCREENSHOT_DIR, 'categories_delete_after_confirm.png') });

            const dbCategoryDeleted = checkDatabase(`echo App\\Models\\Category::find(${categoryId}) ? "EXISTS" : "SOFT_DELETED";`);
            report.modules.Categories.Delete = {
                selector: `button[wire\\:click^="confirmDelete(${categoryId})"]`,
                livewireAction: 'deleteCategory()',
                dbChange: `Lookup: ${dbCategoryDeleted}`,
                status: dbCategoryDeleted === 'SOFT_DELETED' ? 'PASS' : 'FAIL'
            };
        } else {
            console.log('ERROR: Attempt to find "Browser Test Category" in database failed');
            report.modules.Categories.Edit = { status: 'FAIL', reason: 'Category not created' };
            report.modules.Categories.Delete = { status: 'FAIL', reason: 'Category not created' };
        }

        // --- 4. Products Module ---
        console.log('Auditing Products Module...');
        report.modules.Products = {};
        await page.goto(`${BASE_URL}/products`);
        await page.waitForLoadState('networkidle');
        await page.screenshot({ path: path.join(SCREENSHOT_DIR, 'products_list_before.png') });

        // Retrieve active category and supplier IDs
        const catId = checkDatabase('echo App\\Models\\Category::first()->id ?? 1;');
        const supId = checkDatabase('echo App\\Models\\Supplier::first()->id ?? 1;');

        // Create Product
        await page.click('button:has-text("Add Product")');
        await page.waitForTimeout(500);
        await page.fill('input[placeholder="SKU-PRO-NAME"]', productSku);
        await page.fill('input[placeholder="Mechanical Keyboard"]', productName);
        await page.fill('textarea[placeholder="Optional specifications, dimensions, features"]', 'Test board');
        await page.selectOption('select[wire\\:model="categoryId"]', catId.toString());
        await page.selectOption('select[wire\\:model="supplierId"]', supId.toString());
        await page.fill('input[wire\\:model="costPrice"]', '5.50');
        await page.fill('input[wire\\:model="sellingPrice"]', '10.99');
        await page.fill('input[wire\\:model="minimumStock"]', '10');
        await page.selectOption('select[wire\\:model="status"]', 'active');
        await page.screenshot({ path: path.join(SCREENSHOT_DIR, 'products_create_before_submit.png') });
        await page.click('button:has-text("Save Changes")');
        await page.waitForTimeout(1000);
        await page.screenshot({ path: path.join(SCREENSHOT_DIR, 'products_create_after_submit.png') });

        const dbProductCreated = checkDatabase(`echo App\\Models\\Product::where("sku", "${productSku}")->exists() ? "YES" : "NO";`);
        report.modules.Products.Create = {
            selector: 'button:has-text("Add Product") & Save Changes',
            livewireAction: 'saveProduct()',
            dbChange: `Product found: ${dbProductCreated}`,
            status: dbProductCreated === 'YES' ? 'PASS' : 'FAIL'
        };

        if (dbProductCreated === 'YES') {
            const productId = checkDatabase(`echo App\\Models\\Product::where("sku", "${productSku}")->value("id");`);

            // Edit Product
            await page.click(`button[wire\\:click^="openEditModal(${productId})"]`);
            await page.waitForTimeout(500);
            await page.fill('input[placeholder="Mechanical Keyboard"]', productEditName);
            await page.screenshot({ path: path.join(SCREENSHOT_DIR, 'products_edit_before_submit.png') });
            await page.click('button:has-text("Save Changes")');
            await page.waitForTimeout(1000);
            await page.screenshot({ path: path.join(SCREENSHOT_DIR, 'products_edit_after_submit.png') });

            const dbProductUpdated = checkDatabase(`echo App\\Models\\Product::where("sku", "${productSku}")->value("name");`);
            report.modules.Products.Edit = {
                selector: `button[wire\\:click^="openEditModal(${productId})"]`,
                livewireAction: 'saveProduct()',
                dbChange: `Updated Name: ${dbProductUpdated}`,
                status: dbProductUpdated === productEditName ? 'PASS' : 'FAIL'
            };

            // Delete Product
            await page.click(`button[wire\\:click^="confirmDelete(${productId})"]`);
            await page.waitForTimeout(500);
            await page.screenshot({ path: path.join(SCREENSHOT_DIR, 'products_delete_before_confirm.png') });
            await page.click('button:has-text("Delete Product")');
            await page.waitForTimeout(1000);
            await page.screenshot({ path: path.join(SCREENSHOT_DIR, 'products_delete_after_confirm.png') });

            const dbProductDeleted = checkDatabase(`echo App\\Models\\Product::find(${productId}) ? "EXISTS" : "SOFT_DELETED";`);
            report.modules.Products.Delete = {
                selector: `button[wire\\:click^="confirmDelete(${productId})"]`,
                livewireAction: 'deleteProduct()',
                dbChange: `Lookup: ${dbProductDeleted}`,
                status: dbProductDeleted === 'SOFT_DELETED' ? 'PASS' : 'FAIL'
            };
        } else {
            console.log('ERROR: Attempt to find "Browser Product" in database failed');
            report.modules.Products.Edit = { status: 'FAIL', reason: 'Product not created' };
            report.modules.Products.Delete = { status: 'FAIL', reason: 'Product not created' };
        }

        // --- 5. Transactions Module ---
        console.log('Auditing Transactions Module...');
        report.modules.Transactions = {};
        await page.goto(`${BASE_URL}/transactions`);
        await page.waitForLoadState('networkidle');
        await page.screenshot({ path: path.join(SCREENSHOT_DIR, 'transactions_list_before.png') });

        // Retrieve an active product ID
        const prodId = checkDatabase('echo App\\Models\\Product::first()->id ?? 1;');
        const initialStock = checkDatabase(`echo App\\Models\\Product::find(${prodId})->current_stock;`);

        // Stock In
        await page.click('button:has-text("Log Stock Movement")');
        await page.waitForTimeout(500);
        await page.selectOption('select[wire\\:model="productId"]', prodId.toString());
        await page.selectOption('select[wire\\:model\\.live="type"]', 'stock_in');
        await page.fill('input[wire\\:model="quantity"]', '25');
        await page.fill('textarea[wire\\:model="remarks"]', 'Playwright Intake Receipt');
        await page.screenshot({ path: path.join(SCREENSHOT_DIR, 'transactions_stock_in_before_submit.png') });
        await page.click('button:has-text("Record Movement")');
        await page.waitForTimeout(1000);
        await page.screenshot({ path: path.join(SCREENSHOT_DIR, 'transactions_stock_in_after_submit.png') });

        const stockAfterIn = checkDatabase(`echo App\\Models\\Product::find(${prodId})->current_stock;`);
        report.modules.Transactions.StockIn = {
            selector: 'button:has-text("Log Stock Movement") & Record Movement',
            livewireAction: 'saveTransaction()',
            dbChange: `Stock transitions: ${initialStock} -> ${stockAfterIn}`,
            status: Number(stockAfterIn) === (Number(initialStock) + 25) ? 'PASS' : 'FAIL'
        };

        // Stock Out
        await page.click('button:has-text("Log Stock Movement")');
        await page.waitForTimeout(500);
        await page.selectOption('select[wire\\:model="productId"]', prodId.toString());
        await page.selectOption('select[wire\\:model\\.live="type"]', 'stock_out');
        await page.fill('input[wire\\:model="quantity"]', '10');
        await page.fill('textarea[wire\\:model="remarks"]', 'Playwright Dispatch');
        await page.screenshot({ path: path.join(SCREENSHOT_DIR, 'transactions_stock_out_before_submit.png') });
        await page.click('button:has-text("Record Movement")');
        await page.waitForTimeout(1000);
        await page.screenshot({ path: path.join(SCREENSHOT_DIR, 'transactions_stock_out_after_submit.png') });

        const stockAfterOut = checkDatabase(`echo App\\Models\\Product::find(${prodId})->current_stock;`);
        report.modules.Transactions.StockOut = {
            selector: 'button:has-text("Log Stock Movement") & Record Movement',
            livewireAction: 'saveTransaction()',
            dbChange: `Stock transitions: ${stockAfterIn} -> ${stockAfterOut}`,
            status: Number(stockAfterOut) === (Number(stockAfterIn) - 10) ? 'PASS' : 'FAIL'
        };

        // Adjustment (Subtract)
        await page.click('button:has-text("Log Stock Movement")');
        await page.waitForTimeout(500);
        await page.selectOption('select[wire\\:model="productId"]', prodId.toString());
        await page.selectOption('select[wire\\:model\\.live="type"]', 'adjustment');
        await page.selectOption('select[wire\\:model="adjustmentDirection"]', 'subtract');
        await page.fill('input[wire\\:model="quantity"]', '5');
        await page.fill('textarea[wire\\:model="remarks"]', 'Playwright Audit Deduction');
        await page.screenshot({ path: path.join(SCREENSHOT_DIR, 'transactions_adjustment_before_submit.png') });
        await page.click('button:has-text("Record Movement")');
        await page.waitForTimeout(1000);
        await page.screenshot({ path: path.join(SCREENSHOT_DIR, 'transactions_adjustment_after_submit.png') });

        const stockAfterAdj = checkDatabase(`echo App\\Models\\Product::find(${prodId})->current_stock;`);
        report.modules.Transactions.Adjustment = {
            selector: 'button:has-text("Log Stock Movement") & Record Movement',
            livewireAction: 'saveTransaction()',
            dbChange: `Stock transitions: ${stockAfterOut} -> ${stockAfterAdj}`,
            status: Number(stockAfterAdj) === (Number(stockAfterOut) - 5) ? 'PASS' : 'FAIL'
        };

        // --- 6. Reports Module ---
        console.log('Auditing Reports Module...');
        report.modules.Reports = {};
        await page.goto(`${BASE_URL}/reports`);
        await page.waitForLoadState('networkidle');
        await page.screenshot({ path: path.join(SCREENSHOT_DIR, 'reports_dashboard.png') });

        // Download CSV
        const [csvDownload] = await Promise.all([
            page.waitForEvent('download'),
            page.click('button:has-text("Export CSV")')
        ]);
        const csvPath = await csvDownload.path();
        const csvExists = fs.existsSync(csvPath);
        report.modules.Reports.CsvExport = {
            selector: 'button:has-text("Export CSV")',
            livewireAction: 'exportExcel()',
            dbChange: `CSV File Created: ${csvExists}`,
            status: csvExists ? 'PASS' : 'FAIL'
        };

        // Download PDF
        const [pdfDownload] = await Promise.all([
            page.waitForEvent('download'),
            page.click('button:has-text("Export PDF")')
        ]);
        const pdfPath = await pdfDownload.path();
        const pdfExists = fs.existsSync(pdfPath);
        report.modules.Reports.PdfExport = {
            selector: 'button:has-text("Export PDF")',
            livewireAction: 'exportPdf()',
            dbChange: `PDF File Created: ${pdfExists}`,
            status: pdfExists ? 'PASS' : 'FAIL'
        };

    } catch (e) {
        console.error('Fatal E2E error:', e);
        report.fatalError = e.message;
    } finally {
        await browser.close();
        console.log('Browser closed. Saving logs...');
        fs.writeFileSync(
            '/home/james/.gemini/antigravity-cli/brain/f911e69b-acc6-4798-ab23-1a2d8930b952/scratch/playwright_audit_log.json',
            JSON.stringify(report, null, 2)
        );
        console.log('Execution reports complete.');
    }
}

runAudit();

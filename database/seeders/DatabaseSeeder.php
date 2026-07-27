<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\InventoryTransaction;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Reset cached roles and permissions
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // 1. Create Permissions
        $permissions = [
            'manage users',
            'manage suppliers',
            'view suppliers',
            'manage categories',
            'view categories',
            'manage products',
            'view products',
            'manage inventory',
            'view transactions',
            'view reports',
            'export reports',
            'view audit trail',
            'import products',
            'bulk manage products',
            'export catalog',
            'view purchase orders',
            'manage purchase orders',
            'approve purchase orders',
            'receive purchase orders',
        ];

        foreach ($permissions as $permission) {
            Permission::create(['name' => $permission]);
        }

        // 2. Create Roles and Assign Permissions
        $adminRole = Role::create(['name' => 'Admin']);
        $adminRole->givePermissionTo(Permission::all());

        $staffRole = Role::create(['name' => 'Staff']);
        $staffRole->givePermissionTo([
            'view suppliers',
            'view categories',
            'view products',
            'manage inventory',
            'view transactions',
            'view reports',
            'export reports',
            'import products',
            'bulk manage products',
            'export catalog',
            'view purchase orders',
            'manage purchase orders',
            'receive purchase orders',
        ]);

        $supplierRole = Role::create(['name' => 'Supplier']);

        // 3. Seed Realistic Suppliers
        $supApex = Supplier::create([
            'name' => 'Apex Electronics Corp',
            'contact_person' => 'John Doe',
            'email' => 'contact@apex.com',
            'phone' => '+15550192',
            'address' => '123 Tech Blvd, Silicon Valley, CA',
            'status' => 'active',
        ]);

        $supGlobal = Supplier::create([
            'name' => 'Global Logistics Spares',
            'contact_person' => 'Jane Smith',
            'email' => 'sales@globalspares.com',
            'phone' => '+15559876',
            'address' => '456 Freight Rd, Chicago, IL',
            'status' => 'active',
        ]);

        $supPrime = Supplier::create([
            'name' => 'Prime Packaging Solutions',
            'contact_person' => 'Bob Johnson',
            'email' => 'info@primepack.com',
            'phone' => '+15554321',
            'address' => '789 Industrial Pkwy, Dallas, TX',
            'status' => 'inactive',
        ]);

        $supKaeri = Supplier::create([
            'name' => 'Kaeri Logistics',
            'contact_person' => 'Karylle Anne',
            'email' => 'karylleanne.quinto09@gmail.com',
            'phone' => '09430589033',
            'address' => 'Manila, Philippines',
            'status' => 'active',
        ]);

        $supKkomi = Supplier::create([
            'name' => 'kkoMi Corp.',
            'contact_person' => 'James Mikko',
            'email' => 'recariojamesmikko@gmail.com',
            'phone' => '09271622341',
            'address' => 'Quezon City, Philippines',
            'status' => 'active',
        ]);

        $supTechVision = Supplier::create([
            'name' => 'TechVision Hardware Inc.',
            'contact_person' => 'Michael Chang',
            'email' => 'm.chang@techvision.com',
            'phone' => '+1 (555) 321-7654',
            'address' => '500 Oracle Pkwy, Redwood City, CA',
            'status' => 'active',
        ]);

        $supNexus = Supplier::create([
            'name' => 'Nexus Computing Supplies',
            'contact_person' => 'Sarah Jenkins',
            'email' => 's.jenkins@nexuscompute.com',
            'phone' => '+1 (555) 654-3210',
            'address' => '100 1st Ave, New York, NY',
            'status' => 'active',
        ]);

        $supVanguard = Supplier::create([
            'name' => 'Vanguard Network Solutions',
            'contact_person' => 'David Ross',
            'email' => 'd.ross@vanguardnet.com',
            'phone' => '+1 (555) 876-5432',
            'address' => '200 West St, Austin, TX',
            'status' => 'active',
        ]);

        $supOmniData = Supplier::create([
            'name' => 'OmniData Components Ltd.',
            'contact_person' => 'Emily Watson',
            'email' => 'emily.w@omnidata.com',
            'phone' => '+1 (555) 234-5678',
            'address' => '88 Tech Way, Seattle, WA',
            'status' => 'active',
        ]);

        $supPinnacle = Supplier::create([
            'name' => 'Pinnacle Office Systems',
            'contact_person' => 'Robert Taylor',
            'email' => 'rtaylor@pinnacleoffice.com',
            'phone' => '+1 (555) 345-6789',
            'address' => '350 Peachtree St, Atlanta, GA',
            'status' => 'active',
        ]);

        $supHorizon = Supplier::create([
            'name' => 'Horizon Power & Battery',
            'contact_person' => 'Jessica Miller',
            'email' => 'jmiller@horizonpower.com',
            'phone' => '+1 (555) 456-7890',
            'address' => '1200 Commerce Dr, Denver, CO',
            'status' => 'active',
        ]);

        $supSilverline = Supplier::create([
            'name' => 'Silverline Storage Solutions',
            'contact_person' => 'Brian Adams',
            'email' => 'brian@silverlinestorage.com',
            'phone' => '+1 (555) 567-8901',
            'address' => '450 Innovation Blvd, Boston, MA',
            'status' => 'active',
        ]);

        $supCrestview = Supplier::create([
            'name' => 'Crestview Audio & Video',
            'contact_person' => 'Lauren Clark',
            'email' => 'lclark@crestviewav.com',
            'phone' => '+1 (555) 678-9012',
            'address' => '777 Media Lane, Los Angeles, CA',
            'status' => 'active',
        ]);

        $supSummit = Supplier::create([
            'name' => 'Summit Server Infrastructure',
            'contact_person' => 'Kevin Martinez',
            'email' => 'kevin.m@summitserver.com',
            'phone' => '+1 (555) 789-0123',
            'address' => '300 Server Pkwy, San Jose, CA',
            'status' => 'active',
        ]);

        $supProActive = Supplier::create([
            'name' => 'ProActive Security Systems',
            'contact_person' => 'Rachel Green',
            'email' => 'rgreen@proactivesec.com',
            'phone' => '+1 (555) 890-1234',
            'address' => '555 Cyber Dr, Chicago, IL',
            'status' => 'active',
        ]);

        // 4. Seed Users
        $admin = User::create([
            'name' => 'System Administrator',
            'email' => 'admin@sims.com',
            'password' => bcrypt('password'),
            'status' => 'active',
        ]);
        $admin->assignRole($adminRole);

        $karylle = User::create([
            'name' => 'Karylle Anne',
            'email' => 'karylleanne.quinto09@gmail.com',
            'password' => bcrypt('password'),
            'supplier_id' => $supKaeri->id,
            'status' => 'active',
        ]);
        $karylle->assignRole($adminRole);

        $james = User::create([
            'name' => 'James Mikko',
            'email' => 'recariojamesmikko@gmail.com',
            'password' => bcrypt('password'),
            'supplier_id' => $supKkomi->id,
            'status' => 'active',
        ]);
        $james->assignRole($adminRole);

        $staff = User::create([
            'name' => 'Warehouse Operator Staff',
            'email' => 'staff@sims.com',
            'password' => bcrypt('password'),
            'status' => 'active',
        ]);
        $staff->assignRole($staffRole);

        $supplierUser = User::create([
            'name' => 'Apex Rep (Supplier)',
            'email' => 'supplier@sims.com',
            'password' => bcrypt('password'),
            'supplier_id' => $supApex->id,
            'status' => 'active',
        ]);
        $supplierUser->assignRole($supplierRole);

        // Additional Staff Users
        $staffUsers = [
            ['name' => 'Alex Turner', 'email' => 'alex.turner@sims.com'],
            ['name' => 'Samantha Wright', 'email' => 'samantha.wright@sims.com'],
            ['name' => 'Daniel Cooper', 'email' => 'daniel.cooper@sims.com'],
            ['name' => 'Olivia Bennett', 'email' => 'olivia.bennett@sims.com'],
            ['name' => 'Marcus Vance', 'email' => 'marcus.vance@sims.com'],
        ];

        foreach ($staffUsers as $u) {
            $newStaff = User::create([
                'name' => $u['name'],
                'email' => $u['email'],
                'password' => bcrypt('password'),
                'status' => 'active',
            ]);
            $newStaff->assignRole($staffRole);
        }

        // Additional Supplier Rep Users
        $supplierUsers = [
            ['name' => 'Michael Chang Rep', 'email' => 'michael.rep@techvision.com', 'sup' => $supTechVision->id],
            ['name' => 'Sarah Jenkins Rep', 'email' => 'sarah.rep@nexuscompute.com', 'sup' => $supNexus->id],
            ['name' => 'David Ross Rep', 'email' => 'david.rep@vanguardnet.com', 'sup' => $supVanguard->id],
            ['name' => 'Emily Watson Rep', 'email' => 'emily.rep@omnidata.com', 'sup' => $supOmniData->id],
            ['name' => 'Robert Taylor Rep', 'email' => 'robert.rep@pinnacleoffice.com', 'sup' => $supPinnacle->id],
        ];

        foreach ($supplierUsers as $u) {
            $newSupUser = User::create([
                'name' => $u['name'],
                'email' => $u['email'],
                'password' => bcrypt('password'),
                'supplier_id' => $u['sup'],
                'status' => 'active',
            ]);
            $newSupUser->assignRole($supplierRole);
        }

        // 5. Seed Realistic Categories
        $catLaptops = Category::create([
            'name' => 'Laptops & Ultrabooks',
            'description' => 'High-performance business laptops, ultrabooks, and mobile workstations.',
        ]);

        $catDesktops = Category::create([
            'name' => 'Desktop Computers',
            'description' => 'Tower PCs, small form factor desktops, and all-in-one workstations.',
        ]);

        $catMonitors = Category::create([
            'name' => 'Monitors & Displays',
            'description' => 'LED, IPS, 4K, and ultra-wide professional monitors with ergonomic stands.',
        ]);

        $catAccessories = Category::create([
            'name' => 'Computer Accessories',
            'description' => 'Keyboards, mice, webcams, ergonomic wrist rests, and USB hubs.',
        ]);

        $catNetworking = Category::create([
            'name' => 'Networking Equipment',
            'description' => 'Enterprise routers, PoE switches, wireless access points, and network interface cards.',
        ]);

        $catStorage = Category::create([
            'name' => 'Storage & Memory',
            'description' => 'NVMe SSDs, external hard drives, DDR4/DDR5 RAM modules, and NAS units.',
        ]);

        $catPrinters = Category::create([
            'name' => 'Printers & Scanners',
            'description' => 'Laser printers, multifunction office copiers, barcode label printers, and document scanners.',
        ]);

        $catCables = Category::create([
            'name' => 'Cables & Adapters',
            'description' => 'HDMI 2.1 cables, DisplayPort, USB-C thunderbolt adapters, Cat6 ethernet, and power cords.',
        ]);

        $catPower = Category::create([
            'name' => 'Power & Protection',
            'description' => 'Uninterruptible power supplies (UPS), surge protectors, and power distribution units.',
        ]);

        $catAudio = Category::create([
            'name' => 'Audio & Conferencing',
            'description' => 'Noise-canceling headsets, conference speakerphones, 4K webcams, and studio microphones.',
        ]);

        $catServer = Category::create([
            'name' => 'Server Components',
            'description' => 'Rackmount chassis, Xeon server processors, ECC memory, and RAID controllers.',
        ]);

        $catOffice = Category::create([
            'name' => 'Office Stationery',
            'description' => 'Essential office supplies including copy paper, whiteboards, presentation binders, and desk organizers.',
        ]);

        // 6. Seed Curated Realistic Products
        $productsData = [
            // Laptops & Ultrabooks
            [
                'sku' => 'SKU-LAP-TPX1G11',
                'name' => 'ThinkPad X1 Carbon Gen 11',
                'description' => '14-inch ultralight business laptop with Intel Core i7-1370P, 32GB RAM, and 1TB PCIe 4.0 NVMe SSD.',
                'cost_price' => 1250.00,
                'selling_price' => 1599.99,
                'current_stock' => 35,
                'minimum_stock' => 5,
                'category_id' => $catLaptops->id,
                'supplier_id' => $supApex->id,
            ],
            [
                'sku' => 'SKU-LAP-LAT7440',
                'name' => 'Dell Latitude 7440 Ultrabook',
                'description' => '14-inch FHD+ enterprise laptop with Intel Core i5-1335U, 16GB LPDDR5 RAM, and 512GB SSD.',
                'cost_price' => 1100.00,
                'selling_price' => 1449.00,
                'current_stock' => 18,
                'minimum_stock' => 5,
                'category_id' => $catLaptops->id,
                'supplier_id' => $supApex->id,
            ],
            [
                'sku' => 'SKU-LAP-MBP16M3',
                'name' => 'MacBook Pro 16" M3 Max',
                'description' => '16.2-inch Liquid Retina XDR display, 36GB unified memory, 1TB SSD, Space Black.',
                'cost_price' => 2800.00,
                'selling_price' => 3499.00,
                'current_stock' => 8,
                'minimum_stock' => 2,
                'category_id' => $catLaptops->id,
                'supplier_id' => $supTechVision->id,
            ],
            [
                'sku' => 'SKU-LAP-ZBOOK16',
                'name' => 'HP ZBook Fury 16 G10 Workstation',
                'description' => 'Professional workstation laptop featuring NVIDIA RTX 3000 Ada graphics, 64GB DDR5, and Core i9 processor.',
                'cost_price' => 1950.00,
                'selling_price' => 2499.00,
                'current_stock' => 4, // LOW STOCK
                'minimum_stock' => 5,
                'category_id' => $catLaptops->id,
                'supplier_id' => $supTechVision->id,
            ],

            // Desktop Computers
            [
                'sku' => 'SKU-DESK-OPT7000',
                'name' => 'OptiPlex 7000 Micro Form Factor PC',
                'description' => 'Ultra-compact business desktop with Intel Core i7-13700T, 16GB RAM, 512GB NVMe SSD, and Wi-Fi 6E.',
                'cost_price' => 650.00,
                'selling_price' => 899.00,
                'current_stock' => 42,
                'minimum_stock' => 10,
                'category_id' => $catDesktops->id,
                'supplier_id' => $supNexus->id,
            ],
            [
                'sku' => 'SKU-DESK-ELITE800',
                'name' => 'HP EliteDesk 800 G9 Tower',
                'description' => 'Expandable enterprise tower PC with Intel Core i7-13700, 32GB DDR5 RAM, and 1TB PCIe SSD.',
                'cost_price' => 820.00,
                'selling_price' => 1150.00,
                'current_stock' => 20,
                'minimum_stock' => 5,
                'category_id' => $catDesktops->id,
                'supplier_id' => $supNexus->id,
            ],
            [
                'sku' => 'SKU-DESK-IMAC24',
                'name' => 'Apple iMac 24" M3 All-in-One',
                'description' => '24-inch 4.5K Retina display All-in-One computer with Apple M3 chip, 16GB unified memory, and 512GB storage.',
                'cost_price' => 1100.00,
                'selling_price' => 1499.00,
                'current_stock' => 14,
                'minimum_stock' => 4,
                'category_id' => $catDesktops->id,
                'supplier_id' => $supTechVision->id,
            ],
            [
                'sku' => 'SKU-DESK-PROSTATION',
                'name' => 'Custom Xeon E-2378 Workstation',
                'description' => 'Heavy-duty rendering and data analysis workstation with Intel Xeon E-2378, 64GB ECC RAM, and Quadro RTX A4000.',
                'cost_price' => 1600.00,
                'selling_price' => 2199.00,
                'current_stock' => 6,
                'minimum_stock' => 3,
                'category_id' => $catDesktops->id,
                'supplier_id' => $supSummit->id,
            ],

            // Monitors & Displays
            [
                'sku' => 'SKU-MON-U2723QE',
                'name' => 'Dell UltraSharp 27" 4K USB-C Hub Monitor',
                'description' => '27-inch 4K UHD IPS Black display with 98% DCI-P3 color coverage, 90W power delivery, and RJ45 ethernet port.',
                'cost_price' => 420.00,
                'selling_price' => 579.99,
                'current_stock' => 28,
                'minimum_stock' => 8,
                'category_id' => $catMonitors->id,
                'supplier_id' => $supApex->id,
            ],
            [
                'sku' => 'SKU-MON-HPE24G5',
                'name' => 'HP E24 G5 FHD Ergonomic Monitor',
                'description' => '23.8-inch Full HD IPS monitor with 4-way ergonomics, eye-ease blue light filter, and ultra-thin bezels.',
                'cost_price' => 160.00,
                'selling_price' => 229.00,
                'current_stock' => 65,
                'minimum_stock' => 15,
                'category_id' => $catMonitors->id,
                'supplier_id' => $supApex->id,
            ],
            [
                'sku' => 'SKU-MON-34CURVED',
                'name' => 'LG 34" UltraWide QHD Curved Display',
                'description' => '34-inch 21:9 curved WQHD (3440 x 1440) IPS monitor with HDR10 support and 100Hz refresh rate.',
                'cost_price' => 510.00,
                'selling_price' => 699.99,
                'current_stock' => 11,
                'minimum_stock' => 4,
                'category_id' => $catMonitors->id,
                'supplier_id' => $supGlobal->id,
            ],
            [
                'sku' => 'SKU-MON-ASUSPRO27',
                'name' => 'ASUS ProArt 27" 4K HDR Monitor',
                'description' => 'Factory calibrated 100% sRGB and 100% Rec. 709 color accuracy monitor designed for digital designers.',
                'cost_price' => 380.00,
                'selling_price' => 529.00,
                'current_stock' => 3, // LOW STOCK
                'minimum_stock' => 5,
                'category_id' => $catMonitors->id,
                'supplier_id' => $supTechVision->id,
            ],

            // Computer Accessories
            [
                'sku' => 'SKU-MOU-MXM3S',
                'name' => 'Logitech MX Master 3S Wireless Mouse',
                'description' => 'Ergonomic performance mouse with quiet clicks, 8000 DPI track-on-glass sensor, and MagSpeed scrolling.',
                'cost_price' => 65.00,
                'selling_price' => 99.99,
                'current_stock' => 110,
                'minimum_stock' => 20,
                'category_id' => $catAccessories->id,
                'supplier_id' => $supGlobal->id,
            ],
            [
                'sku' => 'SKU-KEY-MXKEYS',
                'name' => 'Logitech MX Keys Advanced Wireless Keyboard',
                'description' => 'Illuminated tactile keyboard with spherical-dished keys, smart backlighting, and USB-C recharging.',
                'cost_price' => 75.00,
                'selling_price' => 119.99,
                'current_stock' => 85,
                'minimum_stock' => 15,
                'category_id' => $catAccessories->id,
                'supplier_id' => $supGlobal->id,
            ],
            [
                'sku' => 'SKU-KEY-KEYCHRONQ1',
                'name' => 'Keychron Q1 Pro Custom Mechanical Keyboard',
                'description' => 'QMK/VIA wireless custom mechanical keyboard with full aluminum CNC machined body and hot-swappable switches.',
                'cost_price' => 140.00,
                'selling_price' => 199.00,
                'current_stock' => 22,
                'minimum_stock' => 5,
                'category_id' => $catAccessories->id,
                'supplier_id' => $supGlobal->id,
            ],
            [
                'sku' => 'SKU-ACC-DOCKWD19',
                'name' => 'Dell WD19S 180W USB-C Docking Station',
                'description' => 'Enterprise commercial docking station supporting up to three displays, Gigabit Ethernet, and 130W power delivery.',
                'cost_price' => 180.00,
                'selling_price' => 259.99,
                'current_stock' => 34,
                'minimum_stock' => 10,
                'category_id' => $catAccessories->id,
                'supplier_id' => $supNexus->id,
            ],

            // Networking Equipment
            [
                'sku' => 'SKU-NET-CISCO24P',
                'name' => 'Cisco Catalyst 1000 24-Port Gigabit Switch',
                'description' => 'Enterprise managed network switch with 24 x 10/100/1000 ports, 4 x SFP uplinks, and fanless desktop design.',
                'cost_price' => 450.00,
                'selling_price' => 629.00,
                'current_stock' => 15,
                'minimum_stock' => 4,
                'category_id' => $catNetworking->id,
                'supplier_id' => $supVanguard->id,
            ],
            [
                'sku' => 'SKU-NET-UBIQAP6',
                'name' => 'Ubiquiti UniFi U6 Enterprise Access Point',
                'description' => 'High-performance Wi-Fi 6E access point supporting 6 GHz band, 2.5 GbE uplink, and over 600 concurrent clients.',
                'cost_price' => 210.00,
                'selling_price' => 299.00,
                'current_stock' => 40,
                'minimum_stock' => 10,
                'category_id' => $catNetworking->id,
                'supplier_id' => $supVanguard->id,
            ],
            [
                'sku' => 'SKU-NET-FORTI60F',
                'name' => 'Fortinet FortiGate 60F Hardware Firewall',
                'description' => 'Secure SD-WAN next-generation firewall appliance with dedicated security processing units and integrated SSL inspection.',
                'cost_price' => 580.00,
                'selling_price' => 799.00,
                'current_stock' => 9,
                'minimum_stock' => 3,
                'category_id' => $catNetworking->id,
                'supplier_id' => $supProActive->id,
            ],
            [
                'sku' => 'SKU-NET-NETGEAR16',
                'name' => 'NETGEAR 16-Port PoE+ Gigabit Unmanaged Switch',
                'description' => 'Plug-and-play rackmount switch with 115W total PoE budget for powering IP cameras, access points, and VoIP phones.',
                'cost_price' => 120.00,
                'selling_price' => 179.99,
                'current_stock' => 50,
                'minimum_stock' => 10,
                'category_id' => $catNetworking->id,
                'supplier_id' => $supVanguard->id,
            ],

            // Storage & Memory
            [
                'sku' => 'SKU-SSD-SAM9902T',
                'name' => 'Samsung 990 PRO 2TB PCIe 4.0 NVMe SSD',
                'description' => 'High-speed M.2 NVMe internal solid state drive with sequential read speeds up to 7,450 MB/s and thermal control.',
                'cost_price' => 115.00,
                'selling_price' => 169.99,
                'current_stock' => 140,
                'minimum_stock' => 25,
                'category_id' => $catStorage->id,
                'supplier_id' => $supSilverline->id,
            ],
            [
                'sku' => 'SKU-SSD-WD4TB',
                'name' => 'WD Red Plus 4TB NAS Hard Drive',
                'description' => '3.5-inch SATA 6 Gb/s 5400 RPM hard drive specifically designed for 24/7 always-on NAS environments.',
                'cost_price' => 85.00,
                'selling_price' => 129.99,
                'current_stock' => 60,
                'minimum_stock' => 15,
                'category_id' => $catStorage->id,
                'supplier_id' => $supSilverline->id,
            ],
            [
                'sku' => 'SKU-RAM-COR32GB',
                'name' => 'Corsair Vengeance 32GB (2x16GB) DDR5 RAM',
                'description' => 'DDR5 6000MHz C30 optimized desktop memory kit with onboard voltage regulation and custom XMP 3.0 profiles.',
                'cost_price' => 70.00,
                'selling_price' => 109.99,
                'current_stock' => 95,
                'minimum_stock' => 20,
                'category_id' => $catStorage->id,
                'supplier_id' => $supOmniData->id,
            ],
            [
                'sku' => 'SKU-NAS-SYN4BAY',
                'name' => 'Synology DiskStation DS923+ 4-Bay NAS',
                'description' => 'Capable 4-bay network attached storage enclosure powered by AMD Ryzen R1600 with dual M.2 NVMe SSD slots for caching.',
                'cost_price' => 480.00,
                'selling_price' => 599.99,
                'current_stock' => 7,
                'minimum_stock' => 2,
                'category_id' => $catStorage->id,
                'supplier_id' => $supSilverline->id,
            ],

            // Printers & Scanners
            [
                'sku' => 'SKU-PRN-HP4001',
                'name' => 'HP LaserJet Pro 4001dn Monochrome Printer',
                'description' => 'High-speed commercial monochrome laser printer featuring automatic two-sided printing and built-in Gigabit Ethernet.',
                'cost_price' => 220.00,
                'selling_price' => 319.00,
                'current_stock' => 16,
                'minimum_stock' => 5,
                'category_id' => $catPrinters->id,
                'supplier_id' => $supPinnacle->id,
            ],
            [
                'sku' => 'SKU-PRN-BROCOLOR',
                'name' => 'Brother HL-L8360CDW Color Laser Printer',
                'description' => 'Business color laser printer with wireless networking, automatic duplex, and high-yield toner cartridge support.',
                'cost_price' => 340.00,
                'selling_price' => 479.99,
                'current_stock' => 12,
                'minimum_stock' => 4,
                'category_id' => $catPrinters->id,
                'supplier_id' => $supPinnacle->id,
            ],
            [
                'sku' => 'SKU-PRN-ZEBRA421',
                'name' => 'Zebra ZD421 Direct Thermal Barcode Printer',
                'description' => 'Desktop thermal label printer designed for shipping labels, asset tracking tags, and inventory barcodes.',
                'cost_price' => 290.00,
                'selling_price' => 410.00,
                'current_stock' => 25,
                'minimum_stock' => 5,
                'category_id' => $catPrinters->id,
                'supplier_id' => $supPinnacle->id,
            ],
            [
                'sku' => 'SKU-SCN-RICOH7160',
                'name' => 'Ricoh fi-7160 High-Speed Document Scanner',
                'description' => 'Duplex color document scanner capable of scanning up to 60 pages per minute with advanced paper feed detection.',
                'cost_price' => 720.00,
                'selling_price' => 995.00,
                'current_stock' => 5,
                'minimum_stock' => 2,
                'category_id' => $catPrinters->id,
                'supplier_id' => $supPinnacle->id,
            ],

            // Cables & Adapters
            [
                'sku' => 'SKU-CAB-HDMI3M',
                'name' => 'Anker Ultra High Speed HDMI 2.1 Cable (10ft)',
                'description' => '48Gbps braided HDMI cable supporting 8K@60Hz and 4K@120Hz dynamic HDR video transmission.',
                'cost_price' => 12.00,
                'selling_price' => 24.99,
                'current_stock' => 250,
                'minimum_stock' => 50,
                'category_id' => $catCables->id,
                'supplier_id' => $supOmniData->id,
            ],
            [
                'sku' => 'SKU-CAB-USBCPD',
                'name' => 'Belkin 100W USB-C to USB-C Braided Cable (6ft)',
                'description' => 'Durable silicone-jacketed fast charging cable supporting USB Power Delivery and 480 Mbps data transfer.',
                'cost_price' => 9.50,
                'selling_price' => 19.99,
                'current_stock' => 300,
                'minimum_stock' => 60,
                'category_id' => $catCables->id,
                'supplier_id' => $supOmniData->id,
            ],
            [
                'sku' => 'SKU-CAB-CAT650FT',
                'name' => 'Tripp Lite Cat6 Gigabit Ethernet Patch Cable (50ft)',
                'description' => 'Snagless molded RJ45 UTP Cat6 network patch cable with gold-plated contacts and strain relief boots.',
                'cost_price' => 14.00,
                'selling_price' => 29.99,
                'current_stock' => 120,
                'minimum_stock' => 30,
                'category_id' => $catCables->id,
                'supplier_id' => $supVanguard->id,
            ],
            [
                'sku' => 'SKU-ADP-USBCHUB7',
                'name' => 'Anker 7-in-1 USB-C Multiport Adapter Hub',
                'description' => 'Compact aluminum USB-C hub featuring 4K HDMI, 100W PD pass-through charging, SD card reader, and 2 USB-A ports.',
                'cost_price' => 25.00,
                'selling_price' => 45.99,
                'current_stock' => 75,
                'minimum_stock' => 15,
                'category_id' => $catCables->id,
                'supplier_id' => $supOmniData->id,
            ],

            // Power & Protection
            [
                'sku' => 'SKU-PWR-APCUPS1500',
                'name' => 'APC Back-UPS Pro 1500VA / 900W Battery Backup',
                'description' => 'Sine wave uninterruptible power supply featuring 10 outlets, AVR voltage regulation, and LCD status display.',
                'cost_price' => 170.00,
                'selling_price' => 249.99,
                'current_stock' => 18,
                'minimum_stock' => 5,
                'category_id' => $catPower->id,
                'supplier_id' => $supHorizon->id,
            ],
            [
                'sku' => 'SKU-PWR-CYBER1000',
                'name' => 'CyberPower CP1000PFCLCD Sinewave UPS System',
                'description' => '1000VA/600W pure sine wave battery backup system designed for workstations and active PFC power supplies.',
                'cost_price' => 135.00,
                'selling_price' => 199.95,
                'current_stock' => 22,
                'minimum_stock' => 6,
                'category_id' => $catPower->id,
                'supplier_id' => $supHorizon->id,
            ],
            [
                'sku' => 'SKU-PWR-SURGE12',
                'name' => 'Belkin 12-Outlet Pivot-Plug Surge Protector',
                'description' => '4,320-joule surge protection strip featuring 8 rotating outlets, 4 fixed outlets, and an 8-foot heavy-duty power cord.',
                'cost_price' => 22.00,
                'selling_price' => 39.99,
                'current_stock' => 150,
                'minimum_stock' => 30,
                'category_id' => $catPower->id,
                'supplier_id' => $supHorizon->id,
            ],
            [
                'sku' => 'SKU-PWR-PDU15A',
                'name' => 'Tripp Lite 15A Rackmount Power Distribution Unit',
                'description' => '1U horizontal server rack mount PDU featuring 13 NEMA 5-15R outlets and 15-amp resettable circuit breaker.',
                'cost_price' => 60.00,
                'selling_price' => 95.00,
                'current_stock' => 30,
                'minimum_stock' => 8,
                'category_id' => $catPower->id,
                'supplier_id' => $supHorizon->id,
            ],

            // Audio & Conferencing
            [
                'sku' => 'SKU-AUD-JABRAE65',
                'name' => 'Jabra Evolve2 65 MS Wireless Stereo Headset',
                'description' => 'Professional Microsoft Teams certified wireless headset featuring passive noise cancellation and 37-hour battery life.',
                'cost_price' => 140.00,
                'selling_price' => 219.00,
                'current_stock' => 45,
                'minimum_stock' => 10,
                'category_id' => $catAudio->id,
                'supplier_id' => $supCrestview->id,
            ],
            [
                'sku' => 'SKU-AUD-POLYSTUDIO',
                'name' => 'HP Poly Studio P15 Personal Video Bar',
                'description' => '4K ultra-HD webcam and smart soundbar with automatic camera framing and NoiseBlockAI acoustic fence technology.',
                'cost_price' => 320.00,
                'selling_price' => 449.00,
                'current_stock' => 12,
                'minimum_stock' => 3,
                'category_id' => $catAudio->id,
                'supplier_id' => $supCrestview->id,
            ],
            [
                'sku' => 'SKU-AUD-YETIMIC',
                'name' => 'Blue Yeti USB Condenser Microphone',
                'description' => 'Multi-pattern USB microphone with studio-grade audio capture, zero-latency headphone monitoring, and gain control.',
                'cost_price' => 70.00,
                'selling_price' => 119.99,
                'current_stock' => 38,
                'minimum_stock' => 8,
                'category_id' => $catAudio->id,
                'supplier_id' => $supCrestview->id,
            ],
            [
                'sku' => 'SKU-AUD-CONFSPK',
                'name' => 'Anker PowerConf S3 Bluetooth Speakerphone',
                'description' => 'Portable conference room speakerphone with 360-degree voice pickup, 6 microphones, and 24-hour call time.',
                'cost_price' => 65.00,
                'selling_price' => 99.99,
                'current_stock' => 50,
                'minimum_stock' => 10,
                'category_id' => $catAudio->id,
                'supplier_id' => $supCrestview->id,
            ],

            // Server Components
            [
                'sku' => 'SKU-SRV-XEONSILVER',
                'name' => 'Intel Xeon Silver 4310 Server Processor',
                'description' => '12-Core 2.40 GHz (3.30 GHz Turbo) Socket LGA 4189 enterprise server processor with 18MB Smart Cache.',
                'cost_price' => 480.00,
                'selling_price' => 650.00,
                'current_stock' => 8,
                'minimum_stock' => 2,
                'category_id' => $catServer->id,
                'supplier_id' => $supSummit->id,
            ],
            [
                'sku' => 'SKU-SRV-ECC64GB',
                'name' => 'Kingston 64GB DDR4 3200MHz ECC Registered RAM',
                'description' => 'Enterprise server memory module featuring Error-Correcting Code (ECC) and thermal monitoring for continuous uptime.',
                'cost_price' => 190.00,
                'selling_price' => 279.00,
                'current_stock' => 24,
                'minimum_stock' => 6,
                'category_id' => $catServer->id,
                'supplier_id' => $supSummit->id,
            ],
            [
                'sku' => 'SKU-SRV-RAIDCTRL',
                'name' => 'Broadcom MegaRAID 9560-8i SAS/SATA Controller',
                'description' => '12Gb/s PCIe 4.0 Tri-Mode RAID storage adapter supporting RAID 0, 1, 5, 6, 10, 50, and 60 configurations.',
                'cost_price' => 550.00,
                'selling_price' => 749.00,
                'current_stock' => 5,
                'minimum_stock' => 2,
                'category_id' => $catServer->id,
                'supplier_id' => $supSummit->id,
            ],
            [
                'sku' => 'SKU-SRV-CHASSIS2U',
                'name' => 'Supermicro 2U Rackmount Server Chassis',
                'description' => '2U server case supporting redundant 800W titanium power supplies and 8 x 3.5-inch hot-swap drive bays.',
                'cost_price' => 380.00,
                'selling_price' => 520.00,
                'current_stock' => 10,
                'minimum_stock' => 3,
                'category_id' => $catServer->id,
                'supplier_id' => $supSummit->id,
            ],

            // Office Stationery
            [
                'sku' => 'SKU-OFF-PAPERA4',
                'name' => 'Hammermill Premium Copy Paper (10 Reams)',
                'description' => '92 brightness 20lb white letter paper carton containing 5,000 sheets for high-volume laser and inkjet printing.',
                'cost_price' => 35.00,
                'selling_price' => 54.99,
                'current_stock' => 180,
                'minimum_stock' => 40,
                'category_id' => $catOffice->id,
                'supplier_id' => $supPrime->id,
            ],
            [
                'sku' => 'SKU-OFF-WBOARD4X3',
                'name' => 'Quartet Magnetic Whiteboard (4ft x 3ft)',
                'description' => 'Smooth aluminum-frame dry erase board with magnetic surface, accessory tray, and mounting hardware included.',
                'cost_price' => 65.00,
                'selling_price' => 109.99,
                'current_stock' => 15,
                'minimum_stock' => 4,
                'category_id' => $catOffice->id,
                'supplier_id' => $supPrime->id,
            ],
            [
                'sku' => 'SKU-OFF-BINDER1IN',
                'name' => 'Avery Heavy-Duty 1-Inch 3-Ring Binders (12-Pack)',
                'description' => 'Durable white slant-ring view binders featuring One Touch open/close mechanism and interior storage pockets.',
                'cost_price' => 28.00,
                'selling_price' => 49.99,
                'current_stock' => 60,
                'minimum_stock' => 15,
                'category_id' => $catOffice->id,
                'supplier_id' => $supPrime->id,
            ],
            [
                'sku' => 'SKU-OFF-DESKORG',
                'name' => 'SimpleHouseware Mesh Desk Organizer with Drawer',
                'description' => 'Black metal mesh office desk storage organizer with sliding drawer and vertical folder tray for clutter-free desks.',
                'cost_price' => 12.50,
                'selling_price' => 22.99,
                'current_stock' => 90,
                'minimum_stock' => 20,
                'category_id' => $catOffice->id,
                'supplier_id' => $supPrime->id,
            ],
        ];

        foreach ($productsData as $data) {
            Product::create($data);
        }

        // 7. Seed Additional Realistic Data via Updated Factories
        Supplier::factory()->count(5)->create();
        Category::factory()->count(3)->create();

        $allSuppliers = Supplier::all();
        $allCategories = Category::all();

        Product::factory()
            ->recycle($allSuppliers)
            ->recycle($allCategories)
            ->count(15)
            ->create();

        // 8. Seed Realistic Inventory Transactions & Calculate Accurate Stock
        $allProducts = Product::all();
        $allUsers = User::all();

        foreach ($allProducts as $product) {
            $currentStock = 0;

            // 1. Initial large stock_in (between 40 and 150 units)
            $initialStock = rand(40, 150);
            InventoryTransaction::create([
                'product_id' => $product->id,
                'user_id' => $allUsers->random()->id,
                'type' => 'stock_in',
                'quantity' => $initialStock,
                'unit_cost' => $product->cost_price,
                'unit_price' => $product->selling_price,
                'remarks' => 'Initial warehouse receiving from vendor invoice #INV-'.rand(10000, 99999),
                'transaction_date' => now()->subDays(rand(20, 30)),
            ]);
            $currentStock += $initialStock;

            // 2. Realistic historical transactions over the past 19 days
            $numTransactions = rand(2, 6);
            for ($i = 0; $i < $numTransactions; $i++) {
                $type = fake()->randomElement(['stock_out', 'stock_out', 'stock_out', 'adjustment']);
                $qty = rand(1, 15);

                if ($type === 'stock_out') {
                    if ($currentStock < $qty) {
                        continue;
                    }
                    $currentStock -= $qty;
                    $remarks = fake()->randomElement([
                        'Dispatched to commercial client order #ORD-'.rand(10000, 99999),
                        'Allocated to internal corporate hardware request #REQ-'.rand(1000, 9999),
                        'Fulfilled B2B enterprise purchase order #PO-'.rand(10000, 99999),
                        'Shipped to regional distribution facility branch #BR-'.rand(100, 999),
                    ]);
                } else { // adjustment
                    $adj = rand(-5, 5);
                    $qty = max(-$currentStock, $adj);
                    $currentStock += $qty;
                    $remarks = fake()->randomElement([
                        'Quarterly inventory physical count audit adjustment.',
                        'Damaged packaging identified during warehouse inspection; written off.',
                        'Stock variance correction after cycle count verification.',
                        'Sample unit returned to active warehouse stock.',
                    ]);
                }

                InventoryTransaction::create([
                    'product_id' => $product->id,
                    'user_id' => $allUsers->random()->id,
                    'type' => $type,
                    'quantity' => $qty,
                    'unit_cost' => $product->cost_price,
                    'unit_price' => $product->selling_price,
                    'remarks' => $remarks,
                    'transaction_date' => now()->subDays(rand(1, 19)),
                ]);
            }

            // Ensure product current_stock exactly matches transaction history
            $product->current_stock = $currentStock;
            $product->save();
        }

        // 9. Seed a representative purchase order pipeline (one order per status).
        $this->call(PurchaseOrderSeeder::class);
    }
}

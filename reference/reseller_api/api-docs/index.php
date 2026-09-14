<?php
require_once dirname(__DIR__, 2) . '/includes/config.php';
$siteName = getSetting('site_name') ?: SITE_NAME;
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>API Documentation</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
@charset "utf-8";

:root { --primary: #ff2d20; --primary-light: #fff4f4; --secondary: #7c69ef; --dark: #1a1a2e; --light: #f8fafc; --gray: #64748b; --gray-light: #e2e8f0; --success: #10b981; --warning: #f59e0b; --danger: #ef4444; --info: #3b82f6; }

body { font-family: Inter, -apple-system, BlinkMacSystemFont, sans-serif; line-height: 1.6; color: var(--dark); background-color: rgb(249, 250, 251); margin: 0px; padding: 0px; }

.container { max-width: 1200px; margin: 0px auto; padding: 20px; }

header { background-color: white; border-bottom: 1px solid var(--gray-light); padding: 1.5rem 0px; margin-bottom: 2rem; }

.header-content { display: flex; justify-content: space-between; align-items: center; }

h1 { margin: 0px; font-size: 2rem; font-weight: 700; color: var(--primary); }

.logo { display: flex; align-items: center; gap: 10px; }

.logo i { font-size: 1.8rem; color: var(--primary); }

h2 { color: var(--dark); margin-top: 2.5rem; font-size: 1.5rem; font-weight: 600; padding-bottom: 0.5rem; border-bottom: 1px solid var(--gray-light); }

h3 { color: var(--dark); margin-top: 1.5rem; font-size: 1.25rem; font-weight: 600; }

.endpoint { background-color: white; border-radius: 0.5rem; padding: 1.5rem; margin-bottom: 1.5rem; box-shadow: rgba(0, 0, 0, 0.05) 0px 1px 3px; border-left: 4px solid var(--primary); }

.method { display: inline-block; padding: 0.3rem 0.8rem; border-radius: 0.25rem; font-weight: 600; margin-right: 10px; font-size: 0.85rem; color: white; }

.get { background-color: var(--success); }

.post { background-color: var(--primary); }

.endpoint-header { display: flex; align-items: center; margin-bottom: 1rem; }

.endpoint-path { font-family: "Fira Code", monospace; background-color: var(--primary-light); padding: 0.5rem 1rem; border-radius: 0.25rem; color: var(--primary); font-size: 0.95rem; }

code { font-family: "Fira Code", monospace; background-color: var(--primary-light); padding: 0.2rem 0.4rem; border-radius: 0.25rem; color: var(--primary); font-size: 0.9rem; }

pre { background-color: rgb(30, 30, 46); color: rgb(248, 248, 242); padding: 1rem; border-radius: 0.5rem; overflow-x: auto; font-size: 0.9rem; line-height: 1.5; margin: 1rem 0px; position: relative; }

pre::before { content: "JSON"; position: absolute; top: 0px; right: 0px; background: rgba(255, 255, 255, 0.1); padding: 0px 0.5rem; font-size: 0.8rem; border-radius: 0px 0px 0px 4px; }

.alert { background-color: white; border-left: 4px solid; padding: 1rem; margin: 1rem 0px; border-radius: 0px 0.25rem 0.25rem 0px; position: relative; box-shadow: rgba(0, 0, 0, 0.05) 0px 1px 2px; }

.alert-primary { border-left-color: var(--primary); background-color: var(--primary-light); }

.alert-warning { border-left-color: var(--warning); background-color: rgba(245, 158, 11, 0.1); }

.alert-success { border-left-color: var(--success); background-color: rgba(16, 185, 129, 0.1); }

.alert-danger { border-left-color: var(--danger); background-color: rgba(239, 68, 68, 0.1); }

.alert-title { font-weight: 600; margin-bottom: 0.5rem; display: flex; align-items: center; gap: 0.5rem; }

table { width: 100%; border-collapse: collapse; margin: 1rem 0px; background: white; border-radius: 0.5rem; overflow: hidden; box-shadow: rgba(0, 0, 0, 0.05) 0px 1px 3px; font-size: 0.9rem; }

th, td { padding: 0.75rem 1rem; text-align: left; border-bottom: 1px solid var(--gray-light); }

th { background-color: var(--primary); color: white; font-weight: 600; }

tr:nth-child(2n) { background-color: rgb(248, 250, 252); }

.base-url { background-color: var(--dark); color: white; padding: 0.75rem 1.5rem; border-radius: 0.5rem; font-family: "Fira Code", monospace; margin: 1rem 0px; display: inline-flex; align-items: center; gap: 0.5rem; }

.badge { display: inline-block; padding: 0.25rem 0.5rem; border-radius: 0.25rem; font-size: 0.75rem; font-weight: 600; margin-left: 0.5rem; }

.badge-success { background-color: rgba(16, 185, 129, 0.1); color: var(--success); }

.badge-warning { background-color: rgba(245, 158, 11, 0.1); color: var(--warning); }

.badge-info { background-color: rgba(59, 130, 246, 0.1); color: var(--info); }

.badge-danger { background-color: rgba(239, 68, 68, 0.1); color: var(--danger); }

.error-codes { margin-top: 3rem; background-color: white; border-radius: 0.5rem; padding: 1.5rem; box-shadow: rgba(0, 0, 0, 0.05) 0px 1px 3px; }

.error-code { display: flex; margin-bottom: 0.5rem; padding-bottom: 0.5rem; border-bottom: 1px solid var(--gray-light); }

.error-code-number { font-weight: bold; width: 50px; color: var(--danger); }

footer { text-align: center; margin-top: 3rem; padding: 2rem 0px; color: var(--gray); font-size: 0.9rem; border-top: 1px solid var(--gray-light); }

.required { color: var(--danger); font-weight: 600; }

.highlight-box { background-color: var(--primary-light); border-left: 4px solid var(--primary); padding: 1rem; margin: 1.5rem 0px; border-radius: 0px 0.25rem 0.25rem 0px; }

.highlight-title { font-weight: 600; margin-bottom: 0.5rem; color: var(--primary); }

@media (max-width: 768px) {
  .container { padding: 15px; }
  h1 { font-size: 1.75rem; }
  h2 { font-size: 1.35rem; }
}
</style>
</head>
<body>
    <header>
        <div class="container header-content">
            <div class="logo">
                <i class="fas fa-fire"></i>
                <h1>API Documentation</h1>
            </div>
            <div class="version"></div>
        </div>
    </header>

    <div class="container">
        <div class="base-url">
            <i class="fas fa-link"></i>
            Base URL: <strong>https://api.njaz.net/</strong>
        </div>

        <div class="alert alert-primary">
            <div class="alert-title">
                <i class="fas fa-info-circle"></i>
                Authentication Required
            </div>
            Include the following header in all API requests:
            <pre><code class="endpoint-path">api-token: YOUR_API_TOKEN</code></pre>
        </div>

        <h2><i class="fas fa-user-circle"></i> Profile</h2>
        <div class="endpoint">
            <div class="endpoint-header">
                <span class="method get">GET</span>
                <div class="endpoint-path">/client/api/profile</div>
            </div>
            <p>Retrieves the user's balance and profile information.</p>

            <h3>Response Example</h3>
            <pre>{
    "balance": "150.500",
    "email": "user@email.com"
}</pre>
        </div>

        <h2><i class="fas fa-boxes"></i> Products</h2>
        <div class="endpoint">
            <div class="endpoint-header">
                <span class="method get">GET</span>
                <div class="endpoint-path">/client/api/products</div>
            </div>
            <p>Retrieves all available products.</p>

            <h3>Response Example</h3>
            <pre>[
    {
        "id": 6,
        "name": "ببجي العالميه 325 شده",
        "price": 4.497,
        "params": ["الايدي"],
        "category_name": "شحن الألعاب",
        "available": true,
        "qty_values": null,
        "product_type": "package",
        "parent_id": 0,
        "base_price": 4.497,
        "category_img": "https://njaz.net/assets/uploads/categories/cat_1.webp"
    },
    {
        "id": 1,
        "name": "وصله",
        "price": 0.0001,
        "params": [],
        "category_name": "شحن التطبيقات",
        "available": true,
        "qty_values": { "min": 10000, "max": 100000 },
        "product_type": "amount",
        "parent_id": 0,
        "base_price": 0.0001,
        "category_img": ""
    }
]</pre>

            <h3>Filter Products by IDs</h3>
            <div class="endpoint-header">
                <span class="method get">GET</span>
                <div class="endpoint-path">/client/api/products?products_id=id1,id2,id3</div>
            </div>
            <p>Retrieves specific products by their IDs.</p>

            <h3>Get Minimal Product Data</h3>
            <div class="endpoint-header">
                <span class="method get">GET</span>
                <div class="endpoint-path">/client/api/products?base=1</div>
            </div>
            <p>Retrieves only product IDs and names.</p>

            <div class="alert alert-warning">
                <div class="alert-title">
                    <i class="fas fa-exclamation-circle"></i>
                    Quantity Values Note
                </div>
                <ul>
                    <li><code>qty_values: null</code> - Quantity in order must be 1</li>
                    <li><code>qty_values: {"min": "500", "max": "500000"}</code> - Quantity must be within this range</li>
                </ul>
            </div>
        </div>

        <h2><i class="fas fa-layer-group"></i> Content</h2>
        <div class="endpoint">
            <div class="endpoint-header">
                <span class="method get">GET</span>
                <div class="endpoint-path">/client/api/content/0</div>
            </div>
            <p>Retrieves products and categories for the home page (parent ID = 0).</p>

            <h3>Get Content for Specific Category</h3>
            <div class="endpoint-header">
                <span class="method get">GET</span>
                <div class="endpoint-path">/client/api/content/[category.id]</div>
            </div>
            <p>Replace <code>[category.id]</code> with the desired category ID to retrieve its products and subcategories.</p>

            <h3>Response Example</h3>
            <pre>{
    "categories": [
        { "id": 4, "name": "PUBG Global ID UC", "image": "" }
    ],
    "products": [ ... same shape as /client/api/products ... ]
}</pre>
        </div>

        <h2><i class="fas fa-shopping-cart"></i> Order</h2>
        <div class="highlight-box">
            <div class="highlight-title">
                <i class="fas fa-shield-alt"></i> Important: Idempotent Requests with order_uuid
            </div>
            <p>The <code>order_uuid</code> parameter is <span class="required">required</span> and serves as a unique identifier for each order request.</p>
            <p>When you send a request with the same <code>order_uuid</code> more than once:</p>
            <ul>
                <li>The system will <strong>not create a duplicate order</strong></li>
                <li>Instead, it will return the <strong>original order data</strong></li>
                <li>This prevents duplicate charges and ensures order idempotency</li>
            </ul>
            <p>Always generate a new UUIDv4 for each unique order attempt.</p>
        </div>

        <div class="endpoint">
            <div class="endpoint-header">
                <span class="method get">GET</span>
                <div class="endpoint-path">/client/api/newOrder/6/params?qty=1&playerId=123456789&order_uuid=ecbdd545-e616-4aee-8770-7eefa977bcd1</div>
            </div>
            <p>Creates a new order for a product. Must include a unique <code>order_uuid</code> to prevent duplicate orders.</p>

            <table>
                <tbody><tr>
                    <th>Parameter</th>
                    <th>Description</th>
                    <th>Required</th>
                </tr>
                <tr>
                    <td>6</td>
                    <td>Product ID (replace with desired product ID from products.id)</td>
                    <td><span class="required">Yes</span></td>
                </tr>
                <tr>
                    <td>qty</td>
                    <td>Quantity of product</td>
                    <td><span class="required">Yes</span></td>
                </tr>
                <tr>
                    <td>playerId / [field name]</td>
                    <td>Any dynamic field required by the product (e.g. player ID)</td>
                    <td>Depends on product</td>
                </tr>
                <tr>
                    <td>order_uuid</td>
                    <td><strong>Unique UUIDv4 identifier</strong> for the order (prevents duplicate orders when retried)</td>
                    <td><span class="required">Yes</span></td>
                </tr>
            </tbody></table>

            <h3>Response Example</h3>
            <pre>{
    "status": "OK",
    "data": {
        "order_id": "ID_9fffb0d849a45215",
        "status": "accept",
        "price": 4.497,
        "data": {
            "playerId": "123456789"
        },
        "replay_api": null
    }
}</pre>

            <div class="alert alert-success">
                <div class="alert-title">
                    <i class="fas fa-check-circle"></i>
                    Status Values
                </div>
                <span class="badge badge-success">accept</span>
                <span class="badge badge-warning">reject</span>
                <span class="badge badge-info">wait</span>
            </div>

            <div class="alert alert-info">
                <div class="alert-title">
                    <i class="fas fa-info-circle"></i>
                    About "wait" status
                </div>
                Orders on products fulfilled from a live code/stock inventory return <code>accept</code> immediately.
                Other products are queued as <code>wait</code> for manual processing and can be tracked via the
                <code>/client/api/check</code> endpoint.
            </div>
        </div>

        <h2><i class="fas fa-search"></i> Check Orders</h2>
        <div class="endpoint">
            <div class="endpoint-header">
                <span class="method get">GET</span>
                <div class="endpoint-path">/client/api/check?orders=[ID_a37aaa06,ID2,ID3]</div>
            </div>
            <p>Checks the status of one or multiple orders.</p>

            <h3>Check by Order UUID</h3>
            <div class="endpoint-header">
                <span class="method get">GET</span>
                <div class="endpoint-path">/client/api/check?orders=[yourOrderUUID]&uuid=1</div>
            </div>
            <p>Replace <code>[ID_a37aaa06]</code> with your order ID or UUID (when using <code>uuid=1</code> parameter).</p>

            <h3>Response Example</h3>
            <pre>{
    "status": "OK",
    "data": [
        {
            "order_id": "ID_9fffb0d849a45215",
            "quantity": 1,
            "data": { "playerId": "123456789" },
            "created_at": "2026-07-28 13:55:48",
            "product_name": "ببجي العالميه 325 شده",
            "price": "4.4970000000",
            "status": "accept",
            "replay_api": null
        }
    ]
}</pre>

            <div class="alert alert-success">
                <div class="alert-title">
                    <i class="fas fa-check-circle"></i>
                    Status Values
                </div>
                <span class="badge badge-success">accept</span>
                <span class="badge badge-warning">reject</span>
                <span class="badge badge-info">wait</span>
            </div>
        </div>

        <h2><i class="fas fa-exclamation-triangle"></i> Error Codes</h2>
        <div class="error-codes">
            <h3>Public Error Codes</h3>
            <div class="error-code">
                <div class="error-code-number">120</div>
                <div>Api Token is required!</div>
            </div>
            <div class="error-code">
                <div class="error-code-number">121</div>
                <div>Token error</div>
            </div>
            <div class="error-code">
                <div class="error-code-number">122</div>
                <div>Not allowed to use API</div>
            </div>
            <div class="error-code">
                <div class="error-code-number">123</div>
                <div>IP not allowed</div>
            </div>
            <div class="error-code">
                <div class="error-code-number">130</div>
                <div>The site is under maintenance</div>
            </div>

            <h3>Order Error Codes</h3>
            <div class="error-code">
                <div class="error-code-number">100</div>
                <div>Insufficient balance</div>
            </div>
            <div class="error-code">
                <div class="error-code-number">105</div>
                <div>Quantity not available</div>
            </div>
            <div class="error-code">
                <div class="error-code-number">106</div>
                <div>Quantity not allowed</div>
            </div>
            <div class="error-code">
                <div class="error-code-number">107</div>
                <div>Player ID blocked</div>
            </div>
            <div class="error-code">
                <div class="error-code-number">108</div>
                <div>2FA required</div>
            </div>
            <div class="error-code">
                <div class="error-code-number">109</div>
                <div>Product deleted or not found</div>
            </div>
            <div class="error-code">
                <div class="error-code-number">110</div>
                <div>Product not available now</div>
            </div>
            <div class="error-code">
                <div class="error-code-number">111</div>
                <div>Try again after 1 minute</div>
            </div>
            <div class="error-code">
                <div class="error-code-number">112</div>
                <div>Quantity is too small</div>
            </div>
            <div class="error-code">
                <div class="error-code-number">113</div>
                <div>Quantity is too large</div>
            </div>
            <div class="error-code">
                <div class="error-code-number">114</div>
                <div>Unknown error</div>
            </div>
            <div class="error-code">
                <div class="error-code-number">500</div>
                <div>Unknown error</div>
            </div>
        </div>

        <footer>
            <p>© <?= date('Y') ?> <?= htmlspecialchars($siteName) ?> API Documentation. All rights reserved.</p>
        </footer>
    </div>
</body>
</html>

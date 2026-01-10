<?php
require_once 'config.php';
header('Content-Type: application/json');

$action = $_GET['action'] ?? '';

switch($action) {
    case 'get_products':
        getProducts();
        break;
    case 'get_product':
        getProduct();
        break;
    case 'search_products':
        searchProducts();
        break;
    case 'place_order':
        placeOrder();
        break;
    case 'buy_now':
        buyNow();
        break;
    default:
        echo json_encode(['error' => 'Invalid action']);
}

function getProducts() {
    global $pdo;
    $category = $_GET['category'] ?? 'all';
    
    try {
        if ($category === 'all' || $category === 'default') {
            $stmt = $pdo->prepare("SELECT * FROM products WHERE status = 'active' ORDER BY created_at DESC");
            $stmt->execute();
        } else {
            $stmt = $pdo->prepare("SELECT * FROM products WHERE category = ? AND status = 'active' ORDER BY created_at DESC");
            $stmt->execute([$category]);
        }
        
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Process images for each product
        foreach ($products as &$product) {
            if ($product['images']) {
                $product['image_data'] = base64_encode($product['images']);
            } else {
                $product['image_data'] = null;
            }
            unset($product['images']); // Remove raw blob data
            
            if ($product['image_names']) {
                $product['image_list'] = explode(',', $product['image_names']);
            } else {
                $product['image_list'] = [];
            }
        }
        
        echo json_encode(['success' => true, 'products' => $products]);
    } catch(Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
}

function getProduct() {
    global $pdo;
    $id = $_GET['id'] ?? '';
    
    if (!$id) {
        echo json_encode(['error' => 'Product ID required']);
        return;
    }
    
    try {
        $stmt = $pdo->prepare("SELECT * FROM products WHERE id = ? AND status = 'active'");
        $stmt->execute([$id]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$product) {
            echo json_encode(['error' => 'Product not found']);
            return;
        }
        
        // Process images
        if ($product['images']) {
            $product['image_data'] = base64_encode($product['images']);
        } else {
            $product['image_data'] = null;
        }
        unset($product['images']);
        
        if ($product['image_names']) {
            $product['image_list'] = explode(',', $product['image_names']);
        } else {
            $product['image_list'] = [];
        }
        
        echo json_encode(['success' => true, 'product' => $product]);
    } catch(Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
}

function searchProducts() {
    global $pdo;
    $query = $_GET['query'] ?? '';
    
    if (!$query) {
        echo json_encode(['success' => true, 'products' => []]);
        return;
    }
    
    try {
        $stmt = $pdo->prepare("SELECT * FROM products WHERE (title LIKE ? OR description LIKE ? OR short_description LIKE ?) AND status = 'active' ORDER BY created_at DESC");
        $searchTerm = '%' . $query . '%';
        $stmt->execute([$searchTerm, $searchTerm, $searchTerm]);
        
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($products as &$product) {
            if ($product['images']) {
                $product['image_data'] = base64_encode($product['images']);
            } else {
                $product['image_data'] = null;
            }
            unset($product['images']);
            
            if ($product['image_names']) {
                $product['image_list'] = explode(',', $product['image_names']);
            } else {
                $product['image_list'] = [];
            }
        }
        
        echo json_encode(['success' => true, 'products' => $products]);
    } catch(Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
}

function generateOrderCode() {
    return str_pad(mt_rand(100000, 999999), 6, '0', STR_PAD_LEFT);
}

function placeOrder() {
    global $pdo;
    $input = json_decode(file_get_contents('php://input'), true);
    $productIds = $input['product_ids'] ?? [];
    $totalAmount = $input['total_amount'] ?? 0;
    
    if (empty($productIds)) {
        echo json_encode(['error' => 'No products in cart']);
        return;
    }
    
    try {
        do {
            $orderCode = generateOrderCode();
            $stmt = $pdo->prepare("SELECT id FROM orders WHERE order_code = ?");
            $stmt->execute([$orderCode]);
        } while ($stmt->fetch());
        
        $stmt = $pdo->prepare("INSERT INTO orders (order_code, product_ids, total_amount, order_type) VALUES (?, ?, ?, 'cart')");
        $stmt->execute([$orderCode, json_encode($productIds), $totalAmount]);
        
        echo json_encode([
            'success' => true, 
            'order_code' => $orderCode,
            'message' => 'Order placed successfully! Call 0989709867 or 0954989877 to complete your purchase. Don\'t forget your order code: ' . $orderCode
        ]);
    } catch(Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
}

function buyNow() {
    global $pdo;
    $input = json_decode(file_get_contents('php://input'), true);
    $productId = $input['product_id'] ?? '';
    $price = $input['price'] ?? 0;
    
    if (!$productId) {
        echo json_encode(['error' => 'Product ID required']);
        return;
    }
    
    try {
        do {
            $orderCode = generateOrderCode();
            $stmt = $pdo->prepare("SELECT id FROM orders WHERE order_code = ?");
            $stmt->execute([$orderCode]);
        } while ($stmt->fetch());
        
        $stmt = $pdo->prepare("INSERT INTO orders (order_code, product_ids, total_amount, order_type) VALUES (?, ?, ?, 'buy_now')");
        $stmt->execute([$orderCode, json_encode([$productId]), $price]);
        
        echo json_encode([
            'success' => true, 
            'order_code' => $orderCode,
            'message' => 'Fast order placed successfully! Call 0989709867 or 0954989877 to complete your purchase. Don\'t forget your order code: ' . $orderCode
        ]);
    } catch(Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
}
?>
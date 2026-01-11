<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Shoptitle</title>
	<link rel="stylesheet" href="./style/style.css">
</head>
<body>
    <!-- Order Success Modal -->
    <div class="modal" id="orderModal">
        <div class="modal-content">
            <span class="modal-close" onclick="closeOrderModal()">&times;</span>
            <div class="modal-title">Order Placed Successfully!</div>
            <div class="modal-message">Your order has been placed. Please call one of the numbers below to complete your purchase:</div>
            <div class="phone-numbers">
                <span class="phone-number" onclick="selectText(this)">📞 0989709867</span>
                <span class="phone-number" onclick="selectText(this)">📞 0954989877</span>
            </div>
            <div class="modal-message">Don't forget your order code:</div>
            <div class="order-code" id="orderCodeDisplay" onclick="selectText(this)">000000</div>
        </div>
    </div>

    <div class="overlay" id="overlay"></div>
    
    <div class="container">
        <div class="navigation">
            <div class="nav-left">
                <div class="burgerMenu" onclick="toggleMenu()">
                    <div></div>
                    <div></div>
                    <div></div>
                </div>
                <div class="logo">
                    <svg width="70" height="28" viewBox="0 0 63 40" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M63 29.3506L38.117 5.08907C37.4008 4.39081 36.4402 4 35.44 4C31.9983 4 30.2991 8.18294 32.7659 10.583L59.4712 36.5666C60.7577 37.8183 59.8715 40 58.0765 40H9.65685C8.59599 40 7.57857 39.5786 6.82843 38.8284L1.17157 33.1716C0.421426 32.4214 0 31.404 0 30.3431V10.6484L24.883 34.9109C25.5992 35.6092 26.5598 36 27.5601 36C31.0017 36 32.7009 31.8171 30.2342 29.417L3.52882 3.43345C2.24227 2.18166 3.12849 0 4.92354 0L53.3431 0C54.404 0 55.4214 0.421427 56.1716 1.17157L61.8284 6.82843C62.5786 7.57857 63 8.59599 63 9.65685V29.3506Z" fill="#007BFF"></path>
                    </svg>
                </div>
            </div>
            <div class="nav-right">
                <div class="searchIcon interactive-element" onclick="showSearchBar()">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="currentColor" class="bi bi-search" viewBox="0 0 16 16">
                        <path d="M11.742 10.344a6.5 6.5 0 1 0-1.397 1.398h-.001q.044.06.098.115l3.85 3.85a1 1 0 0 0 1.415-1.414l-3.85-3.85a1 1 0 0 0-.115-.1zM12 6.5a5.5 5.5 0 1 1-11 0 5.5 5.5 0 0 1 11 0"/>
                    </svg>
                </div>
                <div class="cartIcon interactive-element" onclick="toggleOrderNav()">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="currentColor" class="bi bi-basket" viewBox="0 0 16 16">
                        <path d="M5.757 1.071a.5.5 0 0 1 .172.686L3.383 6h9.234L10.07 1.757a.5.5 0 1 1 .858-.514L13.783 6H15a1 1 0 0 1 1 1v1a1 1 0 0 1-1 1v4.5a2.5 2.5 0 0 1-2.5 2.5h-9A2.5 2.5 0 0 1 1 13.5V9a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h1.217L5.07 1.243a.5.5 0 0 1 .686-.172zM2 9v4.5A1.5 1.5 0 0 0 3.5 15h9a1.5 1.5 0 0 0 1.5-1.5V9zM1 7v1h14V7zm3 3a.5.5 0 0 1 .5.5v3a.5.5 0 0 1-1 0v-3A.5.5 0 0 1 4 10m2 0a.5.5 0 0 1 .5.5v3a.5.5 0 0 1-1 0v-3A.5.5 0 0 1 6 10m2 0a.5.5 0 0 1 .5.5v3a.5.5 0 0 1-1 0v-3A.5.5 0 0 1 8 10m2 0a.5.5 0 0 1 .5.5v3a.5.5 0 0 1-1 0v-3a.5.5 0 0 1 .5-.5"/>
                    </svg>
                </div>
            </div>
        </div>

        <div class="searchBar" id="searchBar">
            <input type="text" placeholder="Search products..." id="searchInput">
            <span class="close interactive-element" onclick="hideSearchBar()">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" class="bi bi-x-lg" viewBox="0 0 16 16">
                    <path d="M2.146 2.854a.5.5 0 1 1 .708-.708L8 7.293l5.146-5.147a.5.5 0 0 1 .708.708L8.707 8l5.147 5.146a.5.5 0 0 1-.708.708L8 8.707l-5.146 5.147a.5.5 0 0 1-.708-.708L7.293 8z"/>
                </svg>
            </span>
        </div>

        <div class="sideNav" id="sideNav">
            <div class="Sidetitle">
               <svg width="70" height="28" viewBox="0 0 63 40" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M63 29.3506L38.117 5.08907C37.4008 4.39081 36.4402 4 35.44 4C31.9983 4 30.2991 8.18294 32.7659 10.583L59.4712 36.5666C60.7577 37.8183 59.8715 40 58.0765 40H9.65685C8.59599 40 7.57857 39.5786 6.82843 38.8284L1.17157 33.1716C0.421426 32.4214 0 31.404 0 30.3431V10.6484L24.883 34.9109C25.5992 35.6092 26.5598 36 27.5601 36C31.0017 36 32.7009 31.8171 30.2342 29.417L3.52882 3.43345C2.24227 2.18166 3.12849 0 4.92354 0L53.3431 0C54.404 0 55.4214 0.421427 56.1716 1.17157L61.8284 6.82843C62.5786 7.57857 63 8.59599 63 9.65685V29.3506Z" fill="#007BFF"></path>
                </svg>
           </div>

            <div class="electronicsCategory sidemenuOptions interactive-element active" onclick="selectCategory('Electronics')">
                <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" fill="currentColor" class="bi bi-phone white-icon" viewBox="0 0 16 16">
                    <path d="M11 1a1 1 0 0 1 1 1v12a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V2a1 1 0 0 1 1-1zM5 0a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2V2a2 2 0 0 0-2-2z"/>
                    <path d="M8 14a1 1 0 1 0 0-2 1 1 0 0 0 0 2"/>
                </svg>
                <span>Electronics</span>
            </div>
            <div class="fashionCategory sidemenuOptions interactive-element" onclick="selectCategory('Fashion')">
                <img class="white-icon" width="22" height="22" src="https://img.icons8.com/external-those-icons-lineal-those-icons/24/external-clothes-hanger-cleaning-housekeeping-those-icons-lineal-those-icons.png" alt="Fashion"/>
                <span>Fashion</span>
            </div>
            <div class="homeGardenCategory sidemenuOptions interactive-element" onclick="selectCategory('Home Accessory')">
                <img class="white-icon" width="22" height="22" src="https://img.icons8.com/dotty/80/home.png" alt="Home"/>
                <span>Home Accessory</span>
            </div>
            <div class="healthBeautyCategory sidemenuOptions interactive-element" onclick="selectCategory('Beauty')">
                <img class="white-icon" width="22" height="22" src="https://img.icons8.com/ios/50/modern-razor.png" alt="Beauty"/>
                <span>Beauty</span>
            </div>
            <div class="sportsOutdoorsCategory sidemenuOptions interactive-element" onclick="selectCategory('Fitness')">
                <img class="white-icon" width="22" height="22" src="https://img.icons8.com/ios/50/dumbbell--v1.png" alt="Fitness"/>
                <span>Fitness</span>
            </div>
            <div class="toysGamesCategory sidemenuOptions interactive-element" onclick="selectCategory('Childrens Toy')">
                <img class="white-icon" width="22" height="22" src="https://img.icons8.com/ios/50/doll.png" alt="Toys"/>
                <span>Childrens Toy</span>
            </div>
            <div class="booksStationeryCategory sidemenuOptions interactive-element" onclick="selectCategory('Books')">
                <img class="white-icon" width="22" height="22" src="https://img.icons8.com/ios/50/tidy-shelf.png" alt="Books"/>
                <span>Books</span>
            </div>
            <div class="petSuppliesCategory sidemenuOptions interactive-element" onclick="selectCategory('Pets')">
                <img class="white-icon" width="22" height="22" src="https://img.icons8.com/ios/50/pets--v1.png" alt="Pets"/>
                <span>Pets</span>
            </div>
            <div class="giftCategory sidemenuOptions interactive-element" onclick="selectCategory('Gifts')">
                <img class="white-icon" width="22" height="22" src="https://img.icons8.com/ios/50/packaging.png" alt="Gifts"/>
                <span>Gifts</span>
            </div>

            <hr style="width: 90%; margin: 20px auto; border-color: var(--border-color);">

            <div class="Theme sidemenuOptions interactive-element" onclick="selectCategory('Theme')">
                <img class="white-icon" width="22" height="22" src="https://img.icons8.com/ios/50/roller-brush--v1.png" alt="Theme"/>
                <span>Theme</span>
            </div>
        </div>

        <div class="orderSideNav" id="orderSideNav">
            <div class="Ordertitle">
               <img width="32" height="32" src="https://img.icons8.com/3d-fluency/94/shopping-cart.png" alt="shopping-cart"/>
                <span>Shopping Cart</span>
            </div> 
            
            <div class="cart-content" id="cartContent">
                <div class="cart-empty">
                    Your cart is empty
                </div>
            </div>
            
            <div class="orderPlacement">
                <div>Total Items: <strong id="totalItems">0</strong></div>
                <div>Total Price: <strong style="color: var(--accent-color);" id="totalPrice">ETB 0.00</strong></div>   
                <button class="button-41" role="button" onclick="placeOrder()">Place Order</button>
            </div>
        </div>

        <!-- Main Content (Product Grid) -->
        <div class="mainContent" id="mainContent">
            <div class="categoryContent" id="defaultContent">
                <!-- Products will be loaded here -->
            </div>
        </div>

        <!-- Product Detail Page -->
        <div class="productDetailPage" id="productDetailPage">
            <div class="productDetailContainer">
                <button class="backButton" onclick="closeProductDetail()">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="currentColor" viewBox="0 0 16 16">
                        <path fill-rule="evenodd" d="M15 8a.5.5 0 0 0-.5-.5H2.707l3.147-3.146a.5.5 0 1 0-.708-.708l-4 4a.5.5 0 0 0 0 .708l4 4a.5.5 0 0 0 .708-.708L2.707 8.5H14.5A.5.5 0 0 0 15 8"/>
                    </svg>
                    Back to Shop
                </button>
                
                <div class="productDetailContent">
                    <div class="productImageSection">
                        <div class="mainProductImage">
                            <div class="productImageContainer">
                                <div id="productMainImage" style="width: 100%; height: 100%; background: linear-gradient(45deg, #333, #555); display: flex; align-items: center; justify-content: center; color: white; font-size: 24px; text-align: center; padding: 20px;">Product Image</div>
                            </div>
                            <div class="productImageControls" id="imageControls" style="display: none;">
                                <button class="imageNavBtn active"></button>
                                <button class="imageNavBtn"></button>
                                <button class="imageNavBtn"></button>
                            </div>
                        </div>
                    </div>
                    
                    <div class="productInfoSection">
                        <h1 class="productTitle" id="productDetailTitle">Product Title</h1>
                        <div class="productPrice" id="productDetailPrice">ETB 0</div>
                        <div class="productDescription" id="productDetailDescription">
                            Product description goes here.
                        </div>
                        <div class="productActions">
                            <button class="addToCartBtn" onclick="addToCartFromDetail()">Add to Cart</button>
                            <button class="buyNowBtn" onclick="buyNowFromDetail()">Buy Now</button>
                        </div>
                    </div>
                </div>
                
                <div class="relatedProductsSection">
                    <h2 class="relatedProductsTitle">Related Products</h2>
                    <div class="relatedProductsGrid" id="relatedProductsGrid">
                        <!-- Related products  -->
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
<script src="./script/script.js"></script>
</html>
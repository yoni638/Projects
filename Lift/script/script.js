        let isSearchBarVisible = false;
        let isSideNavOpen = false;
        let isOrderNavOpen = false;
        let currentSelectedCategory = 'Electronics';
        let navigationHistory = [];
        let cart = [];
        let currentProduct = null;
        let allProducts = [];

        function selectText(element) {
            if (document.selection) {
                const range = document.body.createTextRange();
                range.moveToElementText(element);
                range.select();
            } else if (window.getSelection) {
                const range = document.createRange();
                range.selectNode(element);
                window.getSelection().removeAllRanges();
                window.getSelection().addRange(range);
            }
        }

        function preventBodyScroll(prevent) {
            if (prevent) {
                document.body.classList.add('modal-open');
            } else {
                document.body.classList.remove('modal-open');
            }
        }

        // API Functions
        async function fetchProducts(category = 'all') {
            try {
                const response = await fetch(`api.php?action=get_products&category=${category}`);
                const data = await response.json();
                
                if (data.success) {
                    allProducts = data.products;
                    return data.products;
                } else {
                    console.error('Error fetching products:', data.error);
                    return [];
                }
            } catch (error) {
                console.error('Error:', error);
                return [];
            }
        }

        async function fetchProduct(id) {
            try {
                const response = await fetch(`api.php?action=get_product&id=${id}`);
                const data = await response.json();
                
                if (data.success) {
                    return data.product;
                } else {
                    console.error('Error fetching product:', data.error);
                    return null;
                }
            } catch (error) {
                console.error('Error:', error);
                return null;
            }
        }

        async function searchProducts(query) {
            try {
                const response = await fetch(`api.php?action=search_products&query=${encodeURIComponent(query)}`);
                const data = await response.json();
                
                if (data.success) {
                    return data.products;
                } else {
                    console.error('Error searching products:', data.error);
                    return [];
                }
            } catch (error) {
                console.error('Error:', error);
                return [];
            }
        }

        function generateProductHTML(product) {
            let imageHtml = '';
            if (product.image_data) {
                imageHtml = `<img src="data:image/jpeg;base64,${product.image_data}" alt="${product.title}" class="itemImage">`;
            } else {
                imageHtml = `<div style="width: 100%; height: 100%; background: linear-gradient(45deg, #333, #555); display: flex; align-items: center; justify-content: center; color: white; font-size: 16px; text-align: center; padding: 20px;">No Image</div>`;
            }
            
            return `
                <div class="itemContainer" onclick="openProductDetail(${product.id})">
                    <div class="imagesContainer">
                        ${imageHtml}
                    </div>
                    <div class="itemDescriptionContainer">
                        <div class="item-title">${product.title}</div>
                        <div class="item-price">ETB ${parseFloat(product.price).toFixed(2)}</div>
                        <div class="itemDescription">${product.short_description || 'No description available'}</div>
                        <div class="button-container">
                            <button class="button-7" onclick="event.stopPropagation(); addToCart(${product.id}, '${product.title.replace(/'/g, "\\'")}', ${product.price})">Add to Cart</button>
                        </div>
                    </div>
                </div>
            `;
        }

        function addToCart(productId, title, price) {
            const existingItem = cart.find(item => item.id == productId);
            
            if (existingItem) {
                existingItem.quantity += 1;
            } else {
                cart.push({
                    id: productId,
                    title: title,
                    price: parseFloat(price),
                    quantity: 1
                });
            }
            
            updateCartDisplay();
            
           
            const button = event.target;
            const originalText = button.innerHTML;
            button.innerHTML = '<div class="loading"></div>';
            button.disabled = true;
            
            setTimeout(() => {
                button.innerHTML = 'Added!';
                button.style.background = 'linear-gradient(135deg, #28a745, #20c997)';
                
                setTimeout(() => {
                    button.innerHTML = originalText;
                    button.style.background = '';
                    button.disabled = false;
                }, 1500);
            }, 800);
        }

        function removeFromCart(productId) {
            cart = cart.filter(item => item.id != productId);
            updateCartDisplay();
        }

        function updateCartDisplay() {
            const cartContent = document.getElementById('cartContent');
            const totalItems = document.getElementById('totalItems');
            const totalPrice = document.getElementById('totalPrice');
            
            if (cart.length === 0) {
                cartContent.innerHTML = '<div class="cart-empty">Your cart is empty</div>';
                totalItems.textContent = '0';
                totalPrice.textContent = 'ETB 0.00';
                return;
            }
            
            let cartHtml = '<div class="cart-items">';
            let total = 0;
            let itemCount = 0;
            
            cart.forEach(item => {
                total += item.price * item.quantity;
                itemCount += item.quantity;
                
                cartHtml += `
                    <div class="ordersPlaced">
                        <div class="order-item-header">
                            <div>
                                <div class="item-title">${item.title}</div>
                                <div class="item-price">ETB ${item.price.toFixed(2)} x ${item.quantity}</div>
                            </div>
                            <div class="remove-item interactive-element" onclick="removeFromCart(${item.id})">
                                <img width="24" height="24" src="https://img.icons8.com/fluency/96/clear-symbol.png" alt="Remove"/>
                            </div>
                        </div>
                    </div>
                `;
            });
            
            cartHtml += '</div>';
            cartContent.innerHTML = cartHtml;
            totalItems.textContent = itemCount;
            totalPrice.textContent = `ETB ${total.toFixed(2)}`;
        }

        async function placeOrder() {
            if (cart.length === 0) {
                alert('Your cart is empty!');
                return;
            }
            
            const productIds = cart.map(item => ({ id: item.id, quantity: item.quantity }));
            const totalAmount = cart.reduce((sum, item) => sum + (item.price * item.quantity), 0);
            
            try {
                const response = await fetch('api.php?action=place_order', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        product_ids: productIds,
                        total_amount: totalAmount
                    })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    showOrderModal(data.order_code, data.message);
                    cart = [];
                    updateCartDisplay();
                    toggleOrderNav();
                } else {
                    alert('Error placing order: ' + data.error);
                }
            } catch (error) {
                console.error('Error:', error);
                alert('Error placing order. Please try again.');
            }
        }

        async function buyNow(productId, title, price) {
            try {
                const response = await fetch('api.php?action=buy_now', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        product_id: productId,
                        price: parseFloat(price)
                    })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    showOrderModal(data.order_code, data.message);
                } else {
                    alert('Error placing order: ' + data.error);
                }
            } catch (error) {
                console.error('Error:', error);
                alert('Error placing order. Please try again.');
            }
        }

        function showOrderModal(orderCode, message) {
            document.getElementById('orderCodeDisplay').textContent = orderCode;
            document.getElementById('orderModal').classList.add('active');
            preventBodyScroll(true);
        }

        function closeOrderModal() {
            document.getElementById('orderModal').classList.remove('active');
            preventBodyScroll(false);
        }

        async function renderCategoryProducts(category) {
            const content = document.getElementById('defaultContent');
            if (!content) return;

            // Show loading
            content.innerHTML = '<div style="text-align: center; padding: 40px;"><div class="loading" style="margin: 0 auto;"></div></div>';

            const products = await fetchProducts(category === 'default' ? 'all' : category);
            
            if (products.length === 0) {
                content.innerHTML = `
                    <div class="empty-state">
                        <svg xmlns="http://www.w3.org/2000/svg" width="80" height="80" fill="currentColor" viewBox="0 0 16 16">
                            <path d="M2.97 1.35A1 1 0 0 1 3.73 1h8.54a1 1 0 0 1 .76.35l2.609 3.044A1.5 1.5 0 0 1 16 5.37v.255a2.375 2.375 0 0 1-4.25 1.458A2.371 2.371 0 0 1 9.875 8 2.37 2.37 0 0 1 8 7.083 2.37 2.37 0 0 1 6.125 8a2.37 2.37 0 0 1-1.875-.917A2.375 2.375 0 0 1 0 5.625V5.37a1.5 1.5 0 0 1 .361-.976l2.61-3.045zm1.78 4.275a1.375 1.375 0 0 0 2.75 0 .5.5 0 0 1 1 0 1.375 1.375 0 0 0 2.75 0 .5.5 0 0 1 1 0 1.375 1.375 0 1 0 2.75 0V5.37a.5.5 0 0 0-.12-.325L12.27 2H3.73L1.12 5.045A.5.5 0 0 0 1 5.37v.255a1.375 1.375 0 0 0 2.75 0 .5.5 0 0 1 1 0zM1.5 8.5A.5.5 0 0 1 2 9v6h1v-5a1 1 0 0 1 1-1h3a1 1 0 0 1 1 1v5h6V9a.5.5 0 0 1 1 0v6h.5a.5.5 0 0 1 0 1H.5a.5.5 0 0 1 0-1H1V9a.5.5 0 0 1 .5-.5zM4 15h3v-5H4v5zm5-5a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1v3a1 1 0 0 1-1 1h-2a1 1 0 0 1-1-1v-3zm3 0h-2v3h2v-3z"/>
                        </svg>
                        <div>Database is empty or no active products available</div>
                    </div>
                `;
                return;
            }

            content.innerHTML = products.map(product => generateProductHTML(product)).join('');
        }

        async function openProductDetail(productId) {
            const product = await fetchProduct(productId);
            if (!product) {
                alert('Product not found!');
                return;
            }

            currentProduct = product;
            
            // Save current navigation state
            navigationHistory.push({
                category: currentSelectedCategory,
                view: 'category'
            });

            // Update product details
            document.getElementById('productDetailTitle').textContent = product.title;
            document.getElementById('productDetailPrice').textContent = `ETB ${parseFloat(product.price).toFixed(2)}`;
            document.getElementById('productDetailDescription').textContent = product.description;
            
            // Update main image
            const imageContainer = document.getElementById('productMainImage');
            if (product.image_data) {
                imageContainer.innerHTML = `<img src="data:image/jpeg;base64,${product.image_data}" alt="${product.title}" style="width: 100%; height: 100%; object-fit: cover;">`;
            } else {
                imageContainer.innerHTML = `<div style="width: 100%; height: 100%; background: linear-gradient(45deg, #333, #555); display: flex; align-items: center; justify-content: center; color: white; font-size: 24px; text-align: center; padding: 20px;">No Image Available</div>`;
            }
            
            // Populate related products
            populateRelatedProducts();
            
            // Show product detail page
            document.getElementById('mainContent').style.display = 'none';
            document.getElementById('productDetailPage').classList.add('active');
            
            // Scroll to top
            if (window.innerWidth >= 1024) {
                document.getElementById('productDetailPage').scrollTop = 0;
            } else {
                window.scrollTo(0, 0);
            }
        }

        function addToCartFromDetail() {
            if (currentProduct) {
                addToCart(currentProduct.id, currentProduct.title, currentProduct.price);
            }
        }

        function buyNowFromDetail() {
            if (currentProduct) {
                buyNow(currentProduct.id, currentProduct.title, currentProduct.price);
            }
        }

        function closeProductDetail() {
            document.getElementById('productDetailPage').classList.remove('active');
            document.getElementById('mainContent').style.display = 'block';
            
            // Remove the last navigation state
            if (navigationHistory.length > 0) {
                const lastState = navigationHistory.pop();
                currentSelectedCategory = lastState.category;
            }
            
            // Re-render the previously selected category
            renderCategoryProducts(currentSelectedCategory);
        }

        function populateRelatedProducts() {
            const grid = document.getElementById('relatedProductsGrid');
            grid.innerHTML = '';
            
            // Get other products from the same category
            const relatedProducts = allProducts.filter(p => 
                p.category === currentProduct.category && 
                p.id != currentProduct.id
            ).slice(0, 6);
            
            relatedProducts.forEach(product => {
                const productElement = document.createElement('div');
                productElement.className = 'relatedProductItem';
                productElement.onclick = () => openProductDetail(product.id);
                
                let imageHtml = '';
                if (product.image_data) {
                    imageHtml = `<img src="data:image/jpeg;base64,${product.image_data}" alt="${product.title}" class="relatedProductImage">`;
                } else {
                    imageHtml = `<div class="relatedProductImage" style="background: linear-gradient(45deg, #333, #555); display: flex; align-items: center; justify-content: center; color: white; font-weight: 500; text-align: center; padding: 10px; font-size: 14px;">No Image</div>`;
                }
                
                productElement.innerHTML = `
                    ${imageHtml}
                    <div class="relatedProductInfo">
                        <div class="relatedProductTitle">${product.title}</div>
                        <div class="relatedProductPrice">ETB ${parseFloat(product.price).toFixed(2)}</div>
                    </div>
                `;
                grid.appendChild(productElement);
            });
        }

        function toggleMenu() {
            const sideNav = document.getElementById('sideNav');
            const overlay = document.getElementById('overlay');
            
            isSideNavOpen = !isSideNavOpen;
            
            if (isSideNavOpen) {
                sideNav.classList.add('open');
                overlay.classList.add('active');
                preventBodyScroll(true);
            } else {
                sideNav.classList.remove('open');
                if (!isOrderNavOpen) {
                    overlay.classList.remove('active');
                    preventBodyScroll(false);
                }
            }
        }

        function showSearchBar() {
            if (isSearchBarVisible) return;
            
            const searchBar = document.getElementById('searchBar');
            const navigation = document.querySelector('.navigation');
            
            isSearchBarVisible = true;
            searchBar.classList.add('active');
            
            navigation.style.transition = 'opacity 0.3s ease, transform 0.3s ease';
            navigation.style.opacity = '0';
            navigation.style.transform = 'translateY(-10px)';
            
            setTimeout(() => {
                navigation.style.display = 'none';
                searchBar.querySelector('input').focus();
            }, 300);
        }

        function hideSearchBar() {
            if (!isSearchBarVisible) return;
            
            const searchBar = document.getElementById('searchBar');
            const navigation = document.querySelector('.navigation');
            
            isSearchBarVisible = false;
            
            searchBar.style.transition = 'opacity 0.3s ease, transform 0.3s ease';
            searchBar.style.opacity = '0';
            searchBar.style.transform = 'translateY(-10px)';
            
            setTimeout(() => {
                searchBar.classList.remove('active');
                searchBar.style.opacity = '';
                searchBar.style.transform = '';
                
                navigation.style.display = 'flex';
                setTimeout(() => {
                    navigation.style.opacity = '1';
                    navigation.style.transform = 'translateY(0)';
                }, 10);
            }, 300);
        }

        function toggleOrderNav() {
            const orderNav = document.getElementById('orderSideNav');
            const overlay = document.getElementById('overlay');
            
            isOrderNavOpen = !isOrderNavOpen;
            
            if (isOrderNavOpen) {
                orderNav.classList.add('open');
                overlay.classList.add('active');
                preventBodyScroll(true);
            } else {
                orderNav.classList.remove('open');
                if (!isSideNavOpen) {
                    overlay.classList.remove('active');
                    preventBodyScroll(false);
                }
            }
        }

        function selectCategory(category) {
            currentSelectedCategory = category === 'default' ? 'default' : category;
            
            // Clear navigation history when selecting a new category from the main menu
            navigationHistory = [];
            
            // Close product detail if open
            if (document.getElementById('productDetailPage').classList.contains('active')) {
                closeProductDetail();
            }
            
            const contents = document.querySelectorAll('.categoryContent');
            
            contents.forEach(content => {
                content.classList.add('hidden');
            });
            
            const categories = document.querySelectorAll('.sidemenuOptions');
            categories.forEach(cat => {
                cat.classList.remove('active');
            });
            
            setTimeout(() => {
                renderCategoryProducts(currentSelectedCategory);
                
                const selectedContent = document.getElementById('defaultContent');
                if (selectedContent) {
                    selectedContent.classList.remove('hidden');
                }

                const selectedCategory = [...categories].find(cat => 
                    cat.textContent.trim().includes(category)
                );
                if (selectedCategory) {
                    selectedCategory.classList.add('active');
                }
            }, 150);

            if (window.innerWidth <= 1023 && isSideNavOpen) {
                toggleMenu();
            }
        }

        // Event listeners
        document.addEventListener('click', function(event) {
            const sideNav = document.getElementById('sideNav');
            const burgerMenu = document.querySelector('.burgerMenu');
            const orderNav = document.getElementById('orderSideNav');
            const cartIcon = document.querySelector('.cartIcon');
            const searchBar = document.getElementById('searchBar');
            const searchIcon = document.querySelector('.searchIcon');
            const overlay = document.getElementById('overlay');

            if (!sideNav.contains(event.target) && 
                !burgerMenu.contains(event.target) && 
                isSideNavOpen) {
                toggleMenu();
            }

            if (!orderNav.contains(event.target) && 
                !cartIcon.contains(event.target) && 
                isOrderNavOpen) {
                toggleOrderNav();
            }

            if (!searchBar.contains(event.target) && 
                !searchIcon.contains(event.target) && 
                isSearchBarVisible) {
                hideSearchBar();
            }

            if (event.target === overlay) {
                if (isSideNavOpen) toggleMenu();
                if (isOrderNavOpen) toggleOrderNav();
            }
        });

        window.addEventListener('resize', function() {
            const sideNav = document.getElementById('sideNav');
            const orderNav = document.getElementById('orderSideNav');
            const overlay = document.getElementById('overlay');
            
            if (window.innerWidth >= 1024) {
                sideNav.classList.remove('open');
                if (isOrderNavOpen) {
                    orderNav.classList.add('open');
                } else {
                    orderNav.classList.remove('open');
                }
                overlay.classList.remove('active');
                isSideNavOpen = false;
                preventBodyScroll(false);
            } else {
                if (isSideNavOpen) {
                    sideNav.classList.add('open');
                    overlay.classList.add('active');
                    preventBodyScroll(true);
                } else {
                    sideNav.classList.remove('open');
                    if (!isOrderNavOpen) {
                        overlay.classList.remove('active');
                        preventBodyScroll(false);
                    }
                }
                
                if (isOrderNavOpen) {
                    orderNav.classList.add('open');
                    overlay.classList.add('active');
                    preventBodyScroll(true);
                } else {
                    orderNav.classList.remove('open');
                    if (!isSideNavOpen) {
                        overlay.classList.remove('active');
                        preventBodyScroll(false);
                    }
                }
            }
        });

        document.addEventListener('DOMContentLoaded', function() {
            const searchInput = document.getElementById('searchInput');
            if (searchInput) {
                searchInput.addEventListener('keypress', async function(e) {
                    if (e.key === 'Enter') {
                        const query = this.value.trim();
                        if (query) {
                            const products = await searchProducts(query);
                            const content = document.getElementById('defaultContent');
                            
                            if (products.length === 0) {
                                content.innerHTML = `
                                    <div class="empty-state">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="80" height="80" fill="currentColor" viewBox="0 0 16 16">
                                            <path d="M11.742 10.344a6.5 6.5 0 1 0-1.397 1.398h-.001q.044.06.098.115l3.85 3.85a1 1 0 0 0 1.415-1.414l-3.85-3.85a1 1 0 0 0-.115-.1zM12 6.5a5.5 5.5 0 1 1-11 0 5.5 5.5 0 0 1 11 0"/>
                                        </svg>
                                        <div>No products found for "${query}"</div>
                                    </div>
                                `;
                            } else {
                                content.innerHTML = products.map(product => generateProductHTML(product)).join('');
                            }
                        }
                        hideSearchBar();
                    }
                });
            }
            
            // Initialize with Electronics category
            selectCategory('Electronics');
            document.documentElement.style.scrollBehavior = 'smooth';
        });

        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                if (isSearchBarVisible) hideSearchBar();
                if (isSideNavOpen) toggleMenu();
                if (isOrderNavOpen) toggleOrderNav();
                
                // Close product detail if open
                if (document.getElementById('productDetailPage').classList.contains('active')) {
                    closeProductDetail();
                }
                
                // Close order modal if open
                if (document.getElementById('orderModal').classList.contains('active')) {
                    closeOrderModal();
                }
            }
        });

        // Touch event handling
        let touchStartX = 0;
        let touchEndX = 0;

        document.addEventListener('touchstart', function(event) {
            touchStartX = event.changedTouches[0].screenX;
        }, { passive: true });

        document.addEventListener('touchend', function(event) {
            touchEndX = event.changedTouches[0].screenX;
            handleGesture();
        }, { passive: true });

        function handleGesture() {
            const threshold = 50;
            const swipeDistance = touchEndX - touchStartX;
            
            if (Math.abs(swipeDistance) > threshold) {
                if (swipeDistance > 0 && touchStartX < 50 && !isSideNavOpen && window.innerWidth <= 1023) {
                    toggleMenu();
                } else if (swipeDistance < 0 && isSideNavOpen && window.innerWidth <= 1023) {
                    toggleMenu();
                } else if (swipeDistance < 0 && touchStartX > window.innerWidth - 50 && !isOrderNavOpen && window.innerWidth <= 1023) {
                    toggleOrderNav();
                } else if (swipeDistance > 0 && isOrderNavOpen && window.innerWidth <= 1023) {
                    toggleOrderNav();
                }
            }
        }
 
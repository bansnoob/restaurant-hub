@extends('layouts.store')

@section('title', 'Marugo Takoyaki — Fresh Takoyaki & Beverages')
@section('store-name', 'Marugo Takoyaki')
@section('nav-action-href', '#contact')
@section('nav-action-label', 'Visit Us')

@section('content')

    {{-- HERO --}}
    <section class="rh-store-hero">
        <div class="rh-store-hero-bg" style="background-image: url('{{ asset('img/marugo-takoyaki.jpg') }}'); background-position: center 40%;"></div>
        <div class="rh-store-hero-overlay"></div>
        <div class="rh-store-hero-content">
            <span class="rh-store-hero-badge">Made to Order &middot; Japanese Street Food</span>
            <h1 class="rh-store-hero-title">Marugo<br>Takoyaki</h1>
            <p class="rh-store-hero-tagline">Crisp outside. Molten inside. The art of the perfect bite, made fresh every time.</p>
            <span class="rh-store-hero-scroll">Scroll to explore</span>
        </div>
    </section>

    <div class="rh-store-body">

        {{-- ABOUT --}}
        <section class="rh-store-section">
            <div class="rh-about-grid">
                <div>
                    <p class="rh-section-eyebrow">Our Story</p>
                    <div class="rh-section-rule"></div>
                    <h2 class="rh-section-heading">One Thing.<br>Done Perfectly.</h2>
                    <p class="rh-section-body">
                        Marugo Takoyaki is a dedicated counter with a singular mission — the freshest,
                        most satisfying takoyaki every single time. We use cast-iron griddles seasoned over
                        thousands of batches, dashi-enriched batter, and real octopus in every ball.
                    </p>
                    <p class="rh-section-body" style="margin-top: 1rem;">
                        No compromises on process. No pre-made batches sitting under a lamp. You order,
                        we pour, you wait three minutes, you eat something memorable. That's the deal.
                    </p>
                </div>
                <div class="rh-about-stats">
                    <div class="rh-stat">
                        <div class="rh-stat-value">3<span style="font-size:1.2rem; opacity:0.6"> min</span></div>
                        <div class="rh-stat-label">Fresh cook time, every order</div>
                    </div>
                    <div class="rh-stat">
                        <div class="rh-stat-value">6</div>
                        <div class="rh-stat-label">Signature varieties</div>
                    </div>
                    <div class="rh-stat">
                        <div class="rh-stat-value">₱89</div>
                        <div class="rh-stat-label">Starting price — 6 pcs</div>
                    </div>
                    <div class="rh-stat">
                        <div class="rh-stat-value">0</div>
                        <div class="rh-stat-label">Compromises on quality</div>
                    </div>
                </div>
            </div>
        </section>

        <hr class="rh-section-divider">

        {{-- MENU --}}
        <div class="rh-menu-intro">
            <p class="rh-section-eyebrow">What We Serve</p>
            <div class="rh-section-rule"></div>
            <h2 class="rh-section-heading">The Menu</h2>
            <p class="rh-section-body">
                All takoyaki is cooked to order in our signature cast-iron griddles. Available in 6 or 12 piece servings.
                Pair with any of our imported and house beverages.
            </p>
        </div>

        <div class="rh-menu-grid">

            {{-- Left column --}}
            <div>
                <div class="rh-menu-category">
                    <p class="rh-menu-category-name">Classic Takoyaki &mdash; 6 pcs / 12 pcs</p>
                    <div class="rh-menu-item">
                        <div class="rh-menu-item-left">
                            <p class="rh-menu-item-name">Original</p>
                            <p class="rh-menu-item-desc">Dashi batter, tender octopus, takoyaki sauce, Japanese mayo, bonito flakes, aonori</p>
                        </div>
                        <span class="rh-menu-item-price">₱89 / ₱159</span>
                    </div>
                    <div class="rh-menu-item">
                        <div class="rh-menu-item-left">
                            <p class="rh-menu-item-name">Cheese Lava</p>
                            <p class="rh-menu-item-desc">Melted mozzarella stuffed inside, classic toppings — pulls beautifully when you bite</p>
                        </div>
                        <span class="rh-menu-item-price">₱109 / ₱189</span>
                    </div>
                    <div class="rh-menu-item">
                        <div class="rh-menu-item-left">
                            <p class="rh-menu-item-name">Spicy Mayo</p>
                            <p class="rh-menu-item-desc">Chili-infused Kewpie mayo drizzle, crispy fried shallots, thin-sliced scallion</p>
                        </div>
                        <span class="rh-menu-item-price">₱89 / ₱159</span>
                    </div>
                </div>

                <div class="rh-menu-category">
                    <p class="rh-menu-category-name">Premium Takoyaki &mdash; 6 pcs / 12 pcs</p>
                    <div class="rh-menu-item">
                        <div class="rh-menu-item-left">
                            <p class="rh-menu-item-name">Mentaiko</p>
                            <p class="rh-menu-item-desc">Japanese spicy pollock roe, cream cheese filling, light soy drizzle, chive</p>
                        </div>
                        <span class="rh-menu-item-price">₱129 / ₱229</span>
                    </div>
                    <div class="rh-menu-item">
                        <div class="rh-menu-item-left">
                            <p class="rh-menu-item-name">Truffle Parmesan</p>
                            <p class="rh-menu-item-desc">White truffle oil, freshly grated parmesan, chives — rich and aromatic</p>
                        </div>
                        <span class="rh-menu-item-price">₱139 / ₱249</span>
                    </div>
                    <div class="rh-menu-item">
                        <div class="rh-menu-item-left">
                            <p class="rh-menu-item-name">Unagi Glaze</p>
                            <p class="rh-menu-item-desc">Sweet freshwater eel sauce, toasted sesame, thin cucumber ribbons</p>
                        </div>
                        <span class="rh-menu-item-price">₱129 / ₱229</span>
                    </div>
                    <div class="rh-menu-note">
                        All takoyaki is cooked fresh per order. Allow 2–4 minutes per batch.
                        Gluten-free batter available on request — please inform us when ordering.
                    </div>
                </div>
            </div>

            {{-- Right column --}}
            <div>
                <div class="rh-menu-category">
                    <p class="rh-menu-category-name">Combo Sets</p>
                    <div class="rh-menu-item">
                        <div class="rh-menu-item-left">
                            <p class="rh-menu-item-name">Small Combo</p>
                            <p class="rh-menu-item-desc">Any 6 pcs takoyaki + any one beverage</p>
                        </div>
                        <span class="rh-menu-item-price">from ₱169</span>
                    </div>
                    <div class="rh-menu-item">
                        <div class="rh-menu-item-left">
                            <p class="rh-menu-item-name">Large Combo</p>
                            <p class="rh-menu-item-desc">Any 12 pcs takoyaki + any one beverage</p>
                        </div>
                        <span class="rh-menu-item-price">from ₱229</span>
                    </div>
                    <div class="rh-menu-item">
                        <div class="rh-menu-item-left">
                            <p class="rh-menu-item-name">Sharing Board</p>
                            <p class="rh-menu-item-desc">3 varieties × 6 pcs (18 total) — great for groups, mixed flavors</p>
                        </div>
                        <span class="rh-menu-item-price">from ₱399</span>
                    </div>
                </div>

                <div class="rh-menu-category" style="margin-top: 3.5rem;">
                    <p class="rh-menu-category-name">Beverages</p>
                    <div class="rh-menu-item">
                        <div class="rh-menu-item-left">
                            <p class="rh-menu-item-name">Matcha Latte</p>
                            <p class="rh-menu-item-desc">Ceremonial-grade matcha, oat or whole milk — hot or iced</p>
                        </div>
                        <span class="rh-menu-item-price">₱109</span>
                    </div>
                    <div class="rh-menu-item">
                        <div class="rh-menu-item-left">
                            <p class="rh-menu-item-name">Hojicha Latte</p>
                            <p class="rh-menu-item-desc">Roasted green tea, warm and mellow — hot or iced</p>
                        </div>
                        <span class="rh-menu-item-price">₱109</span>
                    </div>
                    <div class="rh-menu-item">
                        <div class="rh-menu-item-left">
                            <p class="rh-menu-item-name">Yuzu Lemonade</p>
                            <p class="rh-menu-item-desc">Fresh pressed lemon, yuzu concentrate, sparkling water, honey</p>
                        </div>
                        <span class="rh-menu-item-price">₱99</span>
                    </div>
                    <div class="rh-menu-item">
                        <div class="rh-menu-item-left">
                            <p class="rh-menu-item-name">Sakura Milk Tea</p>
                            <p class="rh-menu-item-desc">Floral sakura syrup, whole milk, black tea, tapioca pearls</p>
                        </div>
                        <span class="rh-menu-item-price">₱119</span>
                    </div>
                    <div class="rh-menu-item">
                        <div class="rh-menu-item-left">
                            <p class="rh-menu-item-name">Ramune / Calpico</p>
                            <p class="rh-menu-item-desc">Imported Japanese sodas — assorted flavors</p>
                        </div>
                        <span class="rh-menu-item-price">₱79 – ₱89</span>
                    </div>
                </div>
            </div>

        </div>

        {{-- DETAILS --}}
        <div class="rh-details-bg" id="contact">
            <div class="rh-details-section">
                <p class="rh-section-eyebrow">Plan Your Visit</p>
                <div class="rh-section-rule"></div>
                <h2 class="rh-section-heading">Find Us</h2>
                <div class="rh-details-grid">
                    <div class="rh-detail-block">
                        <svg class="rh-detail-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>
                        </svg>
                        <p class="rh-detail-label">Hours</p>
                        <p class="rh-detail-value">Mon – Fri &nbsp; 11:00 AM – 9:00 PM</p>
                        <p class="rh-detail-sub">Sat – Sun &nbsp;&nbsp; 10:00 AM – 10:00 PM<br>Last order 30 min before close</p>
                    </div>
                    <div class="rh-detail-block">
                        <svg class="rh-detail-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M21 10c0 7-9 13-9 13S3 17 3 10a9 9 0 1 1 18 0z"/><circle cx="12" cy="10" r="3"/>
                        </svg>
                        <p class="rh-detail-label">Location</p>
                        <p class="rh-detail-value">Quezon City<br>Metro Manila</p>
                        <p class="rh-detail-sub">Counter-style dining — dine-in & takeout</p>
                    </div>
                    <div class="rh-detail-block">
                        <svg class="rh-detail-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/>
                        </svg>
                        <p class="rh-detail-label">Good to Know</p>
                        <p class="rh-detail-value">No reservations needed</p>
                        <p class="rh-detail-sub">Takeout available — order 5 min ahead<br>Gluten-free batter on request</p>
                    </div>
                </div>
            </div>
        </div>

        {{-- CTA --}}
        <div class="rh-store-cta">
            <p class="rh-store-cta-eyebrow">Quick. Fresh. Worth It.</p>
            <h2>Come for<br>One. Stay for More.</h2>
            <p>No reservations. No wait. Just walk up, order fresh, and enjoy the best three minutes of your afternoon.</p>
            <div class="rh-cta-buttons">
                <a href="#contact" class="rh-cta-btn">Find Our Location</a>
                <a href="{{ route('welcome') }}" class="rh-cta-ghost">Browse Other Stores</a>
            </div>
        </div>

    </div>

@endsection

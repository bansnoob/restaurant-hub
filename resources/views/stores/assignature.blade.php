@extends('layouts.store')

@section('title', 'Assignature — Premium Samgyupsal')
@section('store-name', 'Assignature')
@section('nav-action-href', 'tel:+63000000000')
@section('nav-action-label', 'Reserve')

@section('content')

    {{-- HERO --}}
    <section class="rh-store-hero">
        <div class="rh-store-hero-bg" style="background-image: url('{{ asset('img/assignature.jpg') }}');"></div>
        <div class="rh-store-hero-overlay"></div>
        <div class="rh-store-hero-content">
            <span class="rh-store-hero-badge">Unlimited &middot; Premium Samgyupsal</span>
            <h1 class="rh-store-hero-title">Assignature</h1>
            <p class="rh-store-hero-tagline">Curated cuts. Unlimited indulgence. Where Korean BBQ meets refined communal dining.</p>
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
                    <h2 class="rh-section-heading">The Art of the Grill</h2>
                    <p class="rh-section-body">
                        Assignature was built on a single obsession — premium Korean BBQ done right, without limits.
                        We source our cuts with the same care a butcher gives their finest selections: pristine pork belly,
                        deeply marinated meats, and premium wagyu options that reward patience on the grill.
                    </p>
                    <p class="rh-section-body" style="margin-top: 1rem;">
                        Every table at Assignature is a stage. Our grill stations are maintained by our floor team so you
                        never have to think about coal — only conversation, laughter, and the perfect sear.
                    </p>
                </div>
                <div class="rh-about-stats">
                    <div class="rh-stat">
                        <div class="rh-stat-value">₱499</div>
                        <div class="rh-stat-label">Starting price per person</div>
                    </div>
                    <div class="rh-stat">
                        <div class="rh-stat-value">90<span style="font-size:1.2rem; opacity:0.6"> min</span></div>
                        <div class="rh-stat-label">Standard dining session</div>
                    </div>
                    <div class="rh-stat">
                        <div class="rh-stat-value">15+</div>
                        <div class="rh-stat-label">Cuts and sides per set</div>
                    </div>
                    <div class="rh-stat">
                        <div class="rh-stat-value">2</div>
                        <div class="rh-stat-label">Branch locations</div>
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
                All unlimited sets include unlimited rice, bottomless ssam vegetables, and a rotating selection of banchan.
                Premium cuts are never frozen — always fresh, always grilled to order.
            </p>
        </div>

        <div class="rh-menu-grid">

            {{-- Left column --}}
            <div>
                <div class="rh-menu-category">
                    <p class="rh-menu-category-name">Unlimited Sets</p>
                    <div class="rh-menu-item">
                        <div class="rh-menu-item-left">
                            <p class="rh-menu-item-name">The Classic</p>
                            <p class="rh-menu-item-desc">Premium pork belly, marinated samgyupsal, unlimited rice and ssam, rotating banchan</p>
                        </div>
                        <span class="rh-menu-item-price">₱499 / pax</span>
                    </div>
                    <div class="rh-menu-item">
                        <div class="rh-menu-item-left">
                            <p class="rh-menu-item-name">The Premium</p>
                            <p class="rh-menu-item-desc">Australian wagyu, Black Angus ribeye, marinated pork belly + all Classic inclusions</p>
                        </div>
                        <span class="rh-menu-item-price">₱799 / pax</span>
                    </div>
                    <div class="rh-menu-item">
                        <div class="rh-menu-item-left">
                            <p class="rh-menu-item-name">Family Package</p>
                            <p class="rh-menu-item-desc">Premium cuts selection, 2 bottomless drink setups. Minimum 4 persons</p>
                        </div>
                        <span class="rh-menu-item-price">₱649 / pax</span>
                    </div>
                    <div class="rh-menu-note">
                        All sets include unlimited rice, ssam (perilla, romaine, red leaf), and daily banchan rotation.
                        90-minute dining window. Extensions available at ₱99/pax per 30 min.
                    </div>
                </div>

                <div class="rh-menu-category">
                    <p class="rh-menu-category-name">À la Carte Add-Ons</p>
                    <div class="rh-menu-item">
                        <div class="rh-menu-item-left">
                            <p class="rh-menu-item-name">Corn Cheese</p>
                            <p class="rh-menu-item-desc">Grilled sweet corn with melted cheese blend, served in foil</p>
                        </div>
                        <span class="rh-menu-item-price">₱89</span>
                    </div>
                    <div class="rh-menu-item">
                        <div class="rh-menu-item-left">
                            <p class="rh-menu-item-name">Cheese Sauce</p>
                            <p class="rh-menu-item-desc">Rich milk-based dipping sauce, warm</p>
                        </div>
                        <span class="rh-menu-item-price">₱49</span>
                    </div>
                    <div class="rh-menu-item">
                        <div class="rh-menu-item-left">
                            <p class="rh-menu-item-name">Spam Slice</p>
                            <p class="rh-menu-item-desc">3 pieces, grilled on request</p>
                        </div>
                        <span class="rh-menu-item-price">₱89</span>
                    </div>
                    <div class="rh-menu-item">
                        <div class="rh-menu-item-left">
                            <p class="rh-menu-item-name">Soft Tofu Jjigae</p>
                            <p class="rh-menu-item-desc">Spicy silken tofu stew, served in stone pot</p>
                        </div>
                        <span class="rh-menu-item-price">₱149</span>
                    </div>
                    <div class="rh-menu-item">
                        <div class="rh-menu-item-left">
                            <p class="rh-menu-item-name">Extra Kimchi</p>
                            <p class="rh-menu-item-desc">House-fermented, medium-spicy</p>
                        </div>
                        <span class="rh-menu-item-price">₱49</span>
                    </div>
                </div>
            </div>

            {{-- Right column --}}
            <div>
                <div class="rh-menu-category">
                    <p class="rh-menu-category-name">Beverages</p>
                    <div class="rh-menu-item">
                        <div class="rh-menu-item-left">
                            <p class="rh-menu-item-name">Soju</p>
                            <p class="rh-menu-item-desc">Chamisul Original or Jinro — served chilled, per bottle</p>
                        </div>
                        <span class="rh-menu-item-price">₱249</span>
                    </div>
                    <div class="rh-menu-item">
                        <div class="rh-menu-item-left">
                            <p class="rh-menu-item-name">Cass Beer</p>
                            <p class="rh-menu-item-desc">Korean lager, 330ml can</p>
                        </div>
                        <span class="rh-menu-item-price">₱139</span>
                    </div>
                    <div class="rh-menu-item">
                        <div class="rh-menu-item-left">
                            <p class="rh-menu-item-name">Korean Yakult</p>
                            <p class="rh-menu-item-desc">Chilled probiotic drink — pairs well with soju</p>
                        </div>
                        <span class="rh-menu-item-price">₱59</span>
                    </div>
                    <div class="rh-menu-item">
                        <div class="rh-menu-item-left">
                            <p class="rh-menu-item-name">Barley Tea</p>
                            <p class="rh-menu-item-desc">Cold-brewed, unsweetened — complimentary with sets</p>
                        </div>
                        <span class="rh-menu-item-price">₱0 / ₱49</span>
                    </div>
                    <div class="rh-menu-item">
                        <div class="rh-menu-item-left">
                            <p class="rh-menu-item-name">Iced Tea</p>
                            <p class="rh-menu-item-desc">Bottomless with set, or standalone</p>
                        </div>
                        <span class="rh-menu-item-price">₱0 / ₱79</span>
                    </div>
                    <div class="rh-menu-item">
                        <div class="rh-menu-item-left">
                            <p class="rh-menu-item-name">Sparkling Water</p>
                            <p class="rh-menu-item-desc">330ml bottle</p>
                        </div>
                        <span class="rh-menu-item-price">₱69</span>
                    </div>
                </div>

                <div class="rh-menu-category" style="margin-top: 3.5rem;">
                    <p class="rh-menu-category-name">Desserts</p>
                    <div class="rh-menu-item">
                        <div class="rh-menu-item-left">
                            <p class="rh-menu-item-name">Bingsu</p>
                            <p class="rh-menu-item-desc">Korean shaved ice — strawberry, mango, or matcha red bean</p>
                        </div>
                        <span class="rh-menu-item-price">₱179</span>
                    </div>
                    <div class="rh-menu-item">
                        <div class="rh-menu-item-left">
                            <p class="rh-menu-item-name">Hotteok</p>
                            <p class="rh-menu-item-desc">Pan-fried sweet pancake, brown sugar and cinnamon filling</p>
                        </div>
                        <span class="rh-menu-item-price">₱89</span>
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
                        <p class="rh-detail-value">Mon – Thu &nbsp; 11:00 AM – 10:00 PM</p>
                        <p class="rh-detail-sub">Fri – Sun &nbsp;&nbsp; 10:00 AM – 11:00 PM<br>Last seating 90 min before close</p>
                    </div>
                    <div class="rh-detail-block">
                        <svg class="rh-detail-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M21 10c0 7-9 13-9 13S3 17 3 10a9 9 0 1 1 18 0z"/><circle cx="12" cy="10" r="3"/>
                        </svg>
                        <p class="rh-detail-label">Location</p>
                        <p class="rh-detail-value">Quezon City<br>Metro Manila</p>
                        <p class="rh-detail-sub">Street-level — walk-ins always welcome</p>
                    </div>
                    <div class="rh-detail-block">
                        <svg class="rh-detail-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/>
                        </svg>
                        <p class="rh-detail-label">Reservations</p>
                        <p class="rh-detail-value">Walk-ins welcome</p>
                        <p class="rh-detail-sub">Groups of 8 or more — call ahead recommended<br>Private dining available</p>
                    </div>
                </div>
            </div>
        </div>

        {{-- CTA --}}
        <div class="rh-store-cta">
            <p class="rh-store-cta-eyebrow">Come Hungry</p>
            <h2>Reserve Your Table</h2>
            <p>Walk-ins are always welcome. For groups or special occasions, reach out and we'll make sure the grill is ready.</p>
            <div class="rh-cta-buttons">
                <a href="tel:+63000000000" class="rh-cta-btn">Call to Reserve</a>
                <a href="{{ route('welcome') }}" class="rh-cta-ghost">Browse Other Stores</a>
            </div>
        </div>

    </div>

@endsection

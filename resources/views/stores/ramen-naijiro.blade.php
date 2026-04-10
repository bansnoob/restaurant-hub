@extends('layouts.store')

@section('title', 'Ramen Naijiro — Ramen & All-You-Can Chicken Wings')
@section('store-name', 'Ramen Naijiro')
@section('nav-action-href', 'tel:+63000000000')
@section('nav-action-label', 'Reserve')

@section('content')

    {{-- HERO --}}
    <section class="rh-store-hero">
        <div class="rh-store-hero-bg" style="background-image: url('{{ asset('img/ramen-naijiro.jpg') }}'); background-position: center 30%;"></div>
        <div class="rh-store-hero-overlay"></div>
        <div class="rh-store-hero-content">
            <span class="rh-store-hero-badge">Ramen &middot; All-You-Can Chicken Wings</span>
            <h1 class="rh-store-hero-title">Ramen<br>Naijiro</h1>
            <p class="rh-store-hero-tagline">Crafted ramen bowls. Boundless wings. The kind of meal you linger over.</p>
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
                    <h2 class="rh-section-heading">The Broth Takes Time.<br>The Wings Are Endless.</h2>
                    <p class="rh-section-body">
                        Ramen Naijiro was born from one obsession — crafting broths that demand hours of patience.
                        Our kitchen starts each morning with bones low-simmering at controlled temperatures,
                        building the depth that defines each bowl. Order what calls to you.
                    </p>
                    <p class="rh-section-body" style="margin-top: 1rem;">
                        Every ramen order comes with unlimited chicken wings in four preparations — sauced,
                        fried, glazed, or fiery. Eat as many as you want while you savour your bowl.
                    </p>
                </div>
                <div class="rh-about-stats">
                    <div class="rh-stat">
                        <div class="rh-stat-value">₱249+</div>
                        <div class="rh-stat-label">Ramen bowls, à la carte</div>
                    </div>
                    <div class="rh-stat">
                        <div class="rh-stat-value">∞</div>
                        <div class="rh-stat-label">Chicken wings with every order</div>
                    </div>
                    <div class="rh-stat">
                        <div class="rh-stat-value">4</div>
                        <div class="rh-stat-label">Ramen varieties in rotation</div>
                    </div>
                    <div class="rh-stat">
                        <div class="rh-stat-value">4</div>
                        <div class="rh-stat-label">Wing preparations — all unlimited</div>
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
                Ramen is ordered à la carte — choose your broth, pick your bowl. Chicken wings are unlimited
                with every ramen order. Noodles are cooked fresh per order. Add-ons billed per item.
            </p>
        </div>

        <div class="rh-menu-grid">

            {{-- Left column --}}
            <div>
                <div class="rh-menu-category">
                    <p class="rh-menu-category-name">Ramen Bowls — À la Carte</p>
                    <div class="rh-menu-item">
                        <div class="rh-menu-item-left">
                            <p class="rh-menu-item-name">Tonkotsu</p>
                            <p class="rh-menu-item-desc">12-hour pork bone broth, chashu pork belly, soft-boiled marinated egg, bamboo shoots, nori, scallion</p>
                        </div>
                        <span class="rh-menu-item-price">₱299</span>
                    </div>
                    <div class="rh-menu-item">
                        <div class="rh-menu-item-left">
                            <p class="rh-menu-item-name">Shoyu</p>
                            <p class="rh-menu-item-desc">Clear soy-seasoned chicken broth, thin wavy noodles, menma, fish cake, spring onion</p>
                        </div>
                        <span class="rh-menu-item-price">₱249</span>
                    </div>
                    <div class="rh-menu-item">
                        <div class="rh-menu-item-left">
                            <p class="rh-menu-item-name">Spicy Miso</p>
                            <p class="rh-menu-item-desc">Hokkaido-style fermented miso base, chili oil, corn, ground pork, butter, thick wavy noodles</p>
                        </div>
                        <span class="rh-menu-item-price">₱279</span>
                    </div>
                    <div class="rh-menu-item">
                        <div class="rh-menu-item-left">
                            <p class="rh-menu-item-name">Chicken Paitan</p>
                            <p class="rh-menu-item-desc">Opaque creamy chicken broth, shimeji mushrooms, garlic oil, soft-boiled egg, yuzu zest</p>
                        </div>
                        <span class="rh-menu-item-price">₱279</span>
                    </div>
                    <div class="rh-menu-note">
                        All ramen orders include unlimited chicken wings. Broth richness and noodle firmness available on request.
                    </div>
                </div>

                <div class="rh-menu-category">
                    <p class="rh-menu-category-name">À la Carte Add-Ons</p>
                    <div class="rh-menu-item">
                        <div class="rh-menu-item-left">
                            <p class="rh-menu-item-name">Extra Chashu</p>
                            <p class="rh-menu-item-desc">3 thick-cut braised pork belly slices</p>
                        </div>
                        <span class="rh-menu-item-price">₱89</span>
                    </div>
                    <div class="rh-menu-item">
                        <div class="rh-menu-item-left">
                            <p class="rh-menu-item-name">Soft-Boiled Egg</p>
                            <p class="rh-menu-item-desc">Marinated in tare overnight, jammy yolk</p>
                        </div>
                        <span class="rh-menu-item-price">₱45</span>
                    </div>
                    <div class="rh-menu-item">
                        <div class="rh-menu-item-left">
                            <p class="rh-menu-item-name">Extra Noodle</p>
                            <p class="rh-menu-item-desc">Fresh-cooked noodle serving</p>
                        </div>
                        <span class="rh-menu-item-price">₱39</span>
                    </div>
                    <div class="rh-menu-item">
                        <div class="rh-menu-item-left">
                            <p class="rh-menu-item-name">Gyoza</p>
                            <p class="rh-menu-item-desc">5 pcs pan-fried pork and cabbage dumplings, yuzu ponzu</p>
                        </div>
                        <span class="rh-menu-item-price">₱99</span>
                    </div>
                </div>
            </div>

            {{-- Right column --}}
            <div>
                <div class="rh-menu-category">
                    <p class="rh-menu-category-name">Unlimited Chicken Wings</p>
                    <div class="rh-menu-item">
                        <div class="rh-menu-item-left">
                            <p class="rh-menu-item-name">Classic Fried</p>
                            <p class="rh-menu-item-desc">Crispy golden batter, light salt seasoning — served with spiced vinegar dip</p>
                        </div>
                        <span class="rh-menu-item-price">Unlimited</span>
                    </div>
                    <div class="rh-menu-item">
                        <div class="rh-menu-item-left">
                            <p class="rh-menu-item-name">Soy Garlic</p>
                            <p class="rh-menu-item-desc">Japanese-style sweet soy glaze with roasted garlic and sesame seed finish</p>
                        </div>
                        <span class="rh-menu-item-price">Unlimited</span>
                    </div>
                    <div class="rh-menu-item">
                        <div class="rh-menu-item-left">
                            <p class="rh-menu-item-name">Buffalo</p>
                            <p class="rh-menu-item-desc">Tangy cayenne hot sauce, side of blue cheese dip and celery sticks</p>
                        </div>
                        <span class="rh-menu-item-price">Unlimited</span>
                    </div>
                    <div class="rh-menu-item">
                        <div class="rh-menu-item-left">
                            <p class="rh-menu-item-name">Honey Gochujang</p>
                            <p class="rh-menu-item-desc">Sweet Korean chili paste glaze, toasted sesame, thin-sliced scallion</p>
                        </div>
                        <span class="rh-menu-item-price">Unlimited</span>
                    </div>
                </div>

                <div class="rh-menu-category" style="margin-top: 3.5rem;">
                    <p class="rh-menu-category-name">Drinks</p>
                    <div class="rh-menu-item">
                        <div class="rh-menu-item-left">
                            <p class="rh-menu-item-name">Ramune Soda</p>
                            <p class="rh-menu-item-desc">Original, strawberry, or lychee — glass bottle</p>
                        </div>
                        <span class="rh-menu-item-price">₱89</span>
                    </div>
                    <div class="rh-menu-item">
                        <div class="rh-menu-item-left">
                            <p class="rh-menu-item-name">Calpico</p>
                            <p class="rh-menu-item-desc">Japanese fermented milk drink — original or mango</p>
                        </div>
                        <span class="rh-menu-item-price">₱79</span>
                    </div>
                    <div class="rh-menu-item">
                        <div class="rh-menu-item-left">
                            <p class="rh-menu-item-name">Japanese Iced Tea</p>
                            <p class="rh-menu-item-desc">Cold-brew hojicha or barley tea, unsweetened</p>
                        </div>
                        <span class="rh-menu-item-price">₱69</span>
                    </div>
                    <div class="rh-menu-item">
                        <div class="rh-menu-item-left">
                            <p class="rh-menu-item-name">Asahi / Sapporo</p>
                            <p class="rh-menu-item-desc">Japanese draft lager, 330ml can</p>
                        </div>
                        <span class="rh-menu-item-price">₱139</span>
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
                        <p class="rh-detail-value">Daily &nbsp; 11:00 AM – 10:00 PM</p>
                        <p class="rh-detail-sub">Last order at 9:15 PM<br>90-minute session per visit</p>
                    </div>
                    <div class="rh-detail-block">
                        <svg class="rh-detail-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M21 10c0 7-9 13-9 13S3 17 3 10a9 9 0 1 1 18 0z"/><circle cx="12" cy="10" r="3"/>
                        </svg>
                        <p class="rh-detail-label">Location</p>
                        <p class="rh-detail-value">Quezon City<br>Metro Manila</p>
                        <p class="rh-detail-sub">Ground floor — walk-ins always welcome</p>
                    </div>
                    <div class="rh-detail-block">
                        <svg class="rh-detail-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>
                        </svg>
                        <p class="rh-detail-label">Groups</p>
                        <p class="rh-detail-value">Walk-ins welcome</p>
                        <p class="rh-detail-sub">Groups of 6+ — reservation recommended<br>Private area available for events</p>
                    </div>
                </div>
            </div>
        </div>

        {{-- CTA --}}
        <div class="rh-store-cta">
            <p class="rh-store-cta-eyebrow">Come Hungry</p>
            <h2>Your Bowl<br>Is Waiting</h2>
            <p>Walk in anytime during operating hours. For large groups or private events, call ahead and we'll set the table.</p>
            <div class="rh-cta-buttons">
                <a href="tel:+63000000000" class="rh-cta-btn">Call to Reserve</a>
                <a href="{{ route('welcome') }}" class="rh-cta-ghost">Browse Other Stores</a>
            </div>
        </div>

    </div>

@endsection

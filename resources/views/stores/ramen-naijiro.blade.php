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
                        <div class="rh-stat-value">₱129+</div>
                        <div class="rh-stat-label">Ramen bowls, starting price</div>
                    </div>
                    <div class="rh-stat">
                        <div class="rh-stat-value">₱199</div>
                        <div class="rh-stat-label">Unlimited wings + unli rice</div>
                    </div>
                    <div class="rh-stat">
                        <div class="rh-stat-value">8</div>
                        <div class="rh-stat-label">Ramen varieties to choose from</div>
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
                Choose from 5 regular ramen bowls or go for one of our Naijiro specials. Pair your bowl with
                unlimited chicken wings and unli rice at ₱199 — available in 8 flavor sauces. Add extras to
                your bowl as you please.
            </p>
        </div>

        <div class="rh-menu-grid">

            {{-- Left column --}}
            <div>
                <div class="rh-menu-category">
                    <p class="rh-menu-category-name">Regular Ramen</p>
                    <div class="rh-menu-item">
                        <div class="rh-menu-item-left">
                            <p class="rh-menu-item-name">Tonkotsu Ramen</p>
                            <p class="rh-menu-item-desc">Creamy pork bone broth with chashu pork, tamago egg, kikurage, wakame and salted seaweed</p>
                        </div>
                        <span class="rh-menu-item-price">₱129</span>
                    </div>
                    <div class="rh-menu-item">
                        <div class="rh-menu-item-left">
                            <p class="rh-menu-item-name">Miso Ramen</p>
                            <p class="rh-menu-item-desc">Miso sauce broth with chashu pork, tamago egg, kikurage, wakame and salted seaweed</p>
                        </div>
                        <span class="rh-menu-item-price">₱159</span>
                    </div>
                    <div class="rh-menu-item">
                        <div class="rh-menu-item-left">
                            <p class="rh-menu-item-name">Shoyu Ramen</p>
                            <p class="rh-menu-item-desc">Nutty, savory broth with chashu pork, tamago egg, kikurage, wakame and salted seaweed</p>
                        </div>
                        <span class="rh-menu-item-price">₱159</span>
                    </div>
                    <div class="rh-menu-item">
                        <div class="rh-menu-item-left">
                            <p class="rh-menu-item-name">Black Garlic Ramen</p>
                            <p class="rh-menu-item-desc">Rich, smoky broth with chashu pork, tamago egg, kikurage, wakame and salted seaweed</p>
                        </div>
                        <span class="rh-menu-item-price">₱179</span>
                    </div>
                    <div class="rh-menu-item">
                        <div class="rh-menu-item-left">
                            <p class="rh-menu-item-name">Tantanmen Ramen</p>
                            <p class="rh-menu-item-desc">Spicy, creamy broth with chashu pork, tamago egg, kikurage, wakame and salted seaweed</p>
                        </div>
                        <span class="rh-menu-item-price">₱179</span>
                    </div>
                </div>

                <div class="rh-menu-category">
                    <p class="rh-menu-category-name">Extras</p>
                    <div class="rh-menu-item">
                        <div class="rh-menu-item-left">
                            <p class="rh-menu-item-name">Wakame</p>
                        </div>
                    </div>
                    <div class="rh-menu-item">
                        <div class="rh-menu-item-left">
                            <p class="rh-menu-item-name">Kikurage</p>
                        </div>
                    </div>
                    <div class="rh-menu-item">
                        <div class="rh-menu-item-left">
                            <p class="rh-menu-item-name">Salted Seaweeds</p>
                        </div>
                    </div>
                    <div class="rh-menu-item">
                        <div class="rh-menu-item-left">
                            <p class="rh-menu-item-name">Tamago Egg</p>
                        </div>
                    </div>
                    <div class="rh-menu-item">
                        <div class="rh-menu-item-left">
                            <p class="rh-menu-item-name">Noodles</p>
                        </div>
                    </div>
                    <div class="rh-menu-item">
                        <div class="rh-menu-item-left">
                            <p class="rh-menu-item-name">Chashu Pork</p>
                        </div>
                    </div>
                    <div class="rh-menu-item">
                        <div class="rh-menu-item-left">
                            <p class="rh-menu-item-name">Kimchi</p>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Right column --}}
            <div>
                <div class="rh-menu-category">
                    <p class="rh-menu-category-name">Naijiro Special Ramen</p>
                    <div class="rh-menu-item">
                        <div class="rh-menu-item-left">
                            <p class="rh-menu-item-name">Naijiro Red Ramen</p>
                            <p class="rh-menu-item-desc">Spicy red broth with chashu pork, tamago egg, kikurage, wakame and salted seaweed</p>
                        </div>
                        <span class="rh-menu-item-price">₱299</span>
                    </div>
                    <div class="rh-menu-item">
                        <div class="rh-menu-item-left">
                            <p class="rh-menu-item-name">Curry Ramen</p>
                            <p class="rh-menu-item-desc">Aromatic curry-flavored broth with chicken katsu, tamago egg, kikurage, wakame and salted seaweed</p>
                        </div>
                        <span class="rh-menu-item-price">₱279</span>
                    </div>
                    <div class="rh-menu-item">
                        <div class="rh-menu-item-left">
                            <p class="rh-menu-item-name">Super Chashu Tantanmen Ramen</p>
                            <p class="rh-menu-item-desc">A rich and creamy broth with chashu pork, tamago egg, kikurage, wakame and salted seaweed</p>
                        </div>
                        <span class="rh-menu-item-price">₱289</span>
                    </div>
                </div>

                <div class="rh-menu-category" style="margin-top: 3.5rem;">
                    <p class="rh-menu-category-name">Unlimited Chicken Wings + Unli Rice</p>
                    <div class="rh-menu-item" style="margin-bottom: 0.25rem;">
                        <div class="rh-menu-item-left">
                            <p class="rh-menu-item-desc">8 flavor sauces to choose from — eat as much as you want</p>
                        </div>
                        <span class="rh-menu-item-price">₱199</span>
                    </div>
                    <div class="rh-menu-item">
                        <div class="rh-menu-item-left"><p class="rh-menu-item-name">Sweet Chili</p></div>
                    </div>
                    <div class="rh-menu-item">
                        <div class="rh-menu-item-left"><p class="rh-menu-item-name">Honey Butter</p></div>
                    </div>
                    <div class="rh-menu-item">
                        <div class="rh-menu-item-left"><p class="rh-menu-item-name">Buffalo Wings</p></div>
                    </div>
                    <div class="rh-menu-item">
                        <div class="rh-menu-item-left"><p class="rh-menu-item-name">Barbeque</p></div>
                    </div>
                    <div class="rh-menu-item">
                        <div class="rh-menu-item-left"><p class="rh-menu-item-name">Teriyaki</p></div>
                    </div>
                    <div class="rh-menu-item">
                        <div class="rh-menu-item-left"><p class="rh-menu-item-name">Sweet &amp; Sour</p></div>
                    </div>
                    <div class="rh-menu-item">
                        <div class="rh-menu-item-left"><p class="rh-menu-item-name">Yangyeom</p></div>
                    </div>
                    <div class="rh-menu-item">
                        <div class="rh-menu-item-left"><p class="rh-menu-item-name">Garlic Parmesan <span style="font-weight:400;">(with cheese)</span></p></div>
                    </div>
                    <div class="rh-menu-item" style="margin-top: 1rem; border-top: 1px solid rgba(0,0,0,0.08); padding-top: 1rem;">
                        <div class="rh-menu-item-left">
                            <p class="rh-menu-item-name">Unlimited Drinks</p>
                            <p class="rh-menu-item-desc">Good for 5 persons</p>
                        </div>
                        <span class="rh-menu-item-price">₱150</span>
                    </div>
                    <div class="rh-menu-item">
                        <div class="rh-menu-item-left">
                            <p class="rh-menu-item-name">One Serving Pitcher</p>
                        </div>
                        <span class="rh-menu-item-price">₱80</span>
                    </div>
                    <div class="rh-menu-note" style="margin-top: 1rem;">
                        Strictly no leftovers policy — leftover chicken is charged at ₱25 per piece. Please order only what you can finish.
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

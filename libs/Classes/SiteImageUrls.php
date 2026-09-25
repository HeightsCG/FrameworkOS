<?php
/**
 * Public URLs of the marketing-page photos (see SiteImages). Tracked in git so every environment gets them.
 * Written by cron/site_images.php (SiteImages::save); after it changes this file, commit it.
 * "<key>.bg" is the small WebP version used behind blurred hero sections.
 */
class SiteImageUrls {
    const URLS = array(
        'best_hero' => 'https://content-os-bucket.s3.us-east-2.amazonaws.com/creator/site/best_hero-da2ee5e5b2.jpg',
        'best_hero.bg' => 'https://content-os-bucket.s3.us-east-2.amazonaws.com/creator/site/best_hero-bg-cab39ee3d2.webp',
        'compare_hero' => 'https://content-os-bucket.s3.us-east-2.amazonaws.com/creator/site/compare_hero-1e3fa9036d.jpg',
        'compare_hero.bg' => 'https://content-os-bucket.s3.us-east-2.amazonaws.com/creator/site/compare_hero-bg-c1f61ba899.webp',
        'features_hero' => 'https://content-os-bucket.s3.us-east-2.amazonaws.com/creator/site/features_hero-65bc2ccc82.jpg',
        'features_hero.bg' => 'https://content-os-bucket.s3.us-east-2.amazonaws.com/creator/site/features_hero-bg-3252e8b8ab.webp',
        'features_page' => 'https://content-os-bucket.s3.us-east-2.amazonaws.com/creator/site/features_page-e6c8776ea6.jpg',
        'features_page.bg' => 'https://content-os-bucket.s3.us-east-2.amazonaws.com/creator/site/features_page-bg-189702f0c5.webp',
        'features_payouts' => 'https://content-os-bucket.s3.us-east-2.amazonaws.com/creator/site/features_payouts-9fdfa532d1.jpg',
        'features_payouts.bg' => 'https://content-os-bucket.s3.us-east-2.amazonaws.com/creator/site/features_payouts-bg-41a07fd6b0.webp',
        'features_studio' => 'https://content-os-bucket.s3.us-east-2.amazonaws.com/creator/site/features_studio-e8a97cc472.jpg',
        'features_studio.bg' => 'https://content-os-bucket.s3.us-east-2.amazonaws.com/creator/site/features_studio-bg-742ceabeae.webp',
        'home_hero' => 'https://content-os-bucket.s3.us-east-2.amazonaws.com/creator/site/home_hero-8b398b5fbf.jpg',
        'home_hero.bg' => 'https://content-os-bucket.s3.us-east-2.amazonaws.com/creator/site/home_hero-bg-8368872751.webp',
        'home_paid' => 'https://content-os-bucket.s3.us-east-2.amazonaws.com/creator/site/home_paid-f8cb726f5a.jpg',
        'home_paid.bg' => 'https://content-os-bucket.s3.us-east-2.amazonaws.com/creator/site/home_paid-bg-748517447a.webp',
        'home_publish' => 'https://content-os-bucket.s3.us-east-2.amazonaws.com/creator/site/home_publish-482b027a63.jpg',
        'home_publish.bg' => 'https://content-os-bucket.s3.us-east-2.amazonaws.com/creator/site/home_publish-bg-b3a845daaf.webp',
        'home_sell' => 'https://content-os-bucket.s3.us-east-2.amazonaws.com/creator/site/home_sell-1d78a1222f.jpg',
        'home_sell.bg' => 'https://content-os-bucket.s3.us-east-2.amazonaws.com/creator/site/home_sell-bg-c9085a8418.webp',
        'monetize_fans' => 'https://content-os-bucket.s3.us-east-2.amazonaws.com/creator/site/monetize_fans-954f38f195.jpg',
        'monetize_fans.bg' => 'https://content-os-bucket.s3.us-east-2.amazonaws.com/creator/site/monetize_fans-bg-dceca86e85.webp',
        'monetize_hero' => 'https://content-os-bucket.s3.us-east-2.amazonaws.com/creator/site/monetize_hero-193fdee741.jpg',
        'monetize_hero.bg' => 'https://content-os-bucket.s3.us-east-2.amazonaws.com/creator/site/monetize_hero-bg-42bbd416b1.webp',
    );
}

<?php
/**
 * Plugin Name: Simple Reviews
 * Description: A simple WordPress plugin that registers a custom post type for product reviews and provides REST API support.
 * Version: 1.0.1
 * Author: Michael Baiyeshea
 */

if (!defined('ABSPATH')) {
    exit;
}

class Simple_Reviews {
    public function __construct() {
        add_action('init', [$this, 'register_product_review_cpt']);

        // Fix: Rest endpoints "register_rest_routes" was not initialized
        add_action('rest_api_init', [$this, 'register_rest_routes']);

        // Fix: Initialize shortcodes
        add_action('init', [$this, 'register_shortcodes']);

    }
    
    /**
     *
     * Register all Shortcodes here
     *
     */
    public function register_shortcodes() {
        // Use [product_reviews]
        add_shortcode( "product_reviews", [ $this, "display_product_reviews" ] );
    }

    public function register_product_review_cpt() {
        register_post_type('product_review', [
            'labels'      => [
                'name'          => 'Product Reviews',
                'singular_name' => 'Product Review'
            ],
            'public'      => true,
            'supports'    => ['title', 'editor', 'custom-fields'],
            'show_in_rest' => true,
        ]);
    }

    public function register_rest_routes() {
        register_rest_route('mock-api/v1', '/sentiment/', [
            'methods'  => 'POST',
            'callback' => [$this, 'analyze_sentiment'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('mock-api/v1', '/review-history/', [
            'methods'  => 'GET',
            'callback' => [$this, 'get_review_history'],
            'permission_callback' => '__return_true',
        ]);

        // Outliers
        register_rest_route( 'mock-api/v1', '/outliers/', [
            'methods'  => 'GET',
            'callback' => [$this, 'get_review_outliers'],
            'permission_callback' => '__return_true',
        ]);
    }

    /**
     *
     * Get Outliers that deviated too much from the average
     *
     */
    public function get_review_outliers($request) {

        /**
         *
         * Pseudocode
         * 1. Get all posts with post_type = product_review
         * 2. Get average and use standard deviation to determine the spread
         * 3. Use s.d to get the threshold
         * 4. Filter out reviews and return outliers
         *
         */

        $return = [];

        $posts = get_posts([
            "post_type" => "product_review",
            "post_status" => "publish",
        ]);

        if( ! empty( $posts ) ){
            $total = count( $posts );
            $sum = 0;

            // Get average
            foreach( $posts as &$p ){
                $score = get_post_meta( $p->ID, 'sentiment_score', true ) ?? 0.5;
                $sentiment = get_post_meta( $p->ID, 'sentiment', true ) ?? 'neutral';
                $p->score = $score;
                $p->sentiment = $sentiment;

                $sum += $score;
            }

            $avg = round( $sum / $total, 4 ); // round to 4 decimal places

            // Calculate deviation using normal distribution
            $sumemd_diff = 0;

            foreach( $posts as $post ){
                $diff = $post->score - $avg;
                $sumemd_diff += $diff * $diff;
            }

            $sd = round( sqrt( $sumemd_diff / $total ), 4 ); // round to 4 decimal places
            $deviationMetric = $sd;
            // Rather than hard-coding, we can use standard deviation to get devation using normal distibution 
            // $deviationMetric = 0.2;

            $lowThreshold = $avg - $deviationMetric;
            $highThreshold = $avg + $deviationMetric;
            // print_r( $lowThreshold . '-' . $highThreshold );exit;

            // Filter out reviews based on threshold
            $data = [];
            foreach( $posts as $post ){
                if( $post->score < $lowThreshold || $post->score > $highThreshold ){

                    $pt = [
                        'ID' => $post->ID,
                        'title' => $post->post_title,
                        'content' => $post->post_content,
                        'sentiment' => $post->sentiment,
                        'sentiment_score' => $post->score,
                    ];

                    $data[] = $pt;
                }
            }

            if( ! empty( $data ) ){
                $return = [
                    "data" => $data,
                    "meta" => [
                        'mean' => $avg,
                        'standard_deviation' => $sd,
                        'lowThreshold' => $lowThreshold,
                        'highThreshold' => $highThreshold,
                    ],
                ];
            }else{
                return new WP_Error( "no_data", "No reviews found with scores that deviated too much from the average", [ 'code' => 500 ] );
            }

        }else{
            return new WP_Error( "no_data", "No Product Review Posts Available", [ 'code' => 500 ] );
        }

        return rest_ensure_response( $return );

    }

    public function analyze_sentiment($request) {
        $params = $request->get_json_params();
        $text = isset($params['text']) ? sanitize_text_field($params['text']) : '';
        
        if (empty($text)) {
            return new WP_Error('empty_text', 'No text provided for analysis.', ['status' => 400]);
        }

        $sentiment_scores = ['positive' => 0.9, 'negative' => 0.2, 'neutral' => 0.5];
        $random_sentiment = array_rand($sentiment_scores);
        return rest_ensure_response(['sentiment' => $random_sentiment, 'score' => $sentiment_scores[$random_sentiment]]);
    }

    public function get_review_history() {
        $reviews = get_posts([
            'post_type'      => 'product_review',
            'posts_per_page' => 5,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ]);
        
        $response = [];
        foreach ($reviews as $review) {
            $response[] = [
                'id'       => $review->ID,
                'title'    => $review->post_title,
                'sentiment'=> get_post_meta($review->ID, 'sentiment', true) ?? 'neutral',
                'score'    => get_post_meta($review->ID, 'sentiment_score', true) ?? 0.5,
            ];
        }

        return rest_ensure_response($response);
    }

    public function display_product_reviews() {
        $reviews = get_posts([
            'post_type'      => 'product_review',
            'posts_per_page' => 5,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ]);

        $output = '<style>
            .review-positive { color: green; font-weight: bold; }
            .review-negative { color: red; font-weight: bold; }
        </style>';

        $output .= '<ul>';
        foreach ($reviews as $review) {
            $sentiment = get_post_meta($review->ID, 'sentiment', true) ?? 'neutral';
            $class = ($sentiment === 'positive') ? 'review-positive' : (($sentiment === 'negative') ? 'review-negative' : '');
            $output .= "<li class='$class'>{$review->post_title} (Sentiment: $sentiment)</li>";
        }
        $output .= '</ul>';

        return $output;
    }
}

// Initialization of the Plugin
new Simple_Reviews();

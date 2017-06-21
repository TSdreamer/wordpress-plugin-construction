<?php
/*
Plugin name: Tiny cache (MU)
Description: Cache HTML content in persistent object cache during the_content() calls.
Version: 0.5.0
Plugin URI: https://developer.wordpress.org/reference/functions/the_content/
*/

/*
README.md

Replace the_content(); instances
--------------------------------

    `find -type f -name "*.php" | xargs -r -L 1 sed -i -e 's|\bthe_content();|the_content_cached();|g'`
    Replace only **argument-less** calls! Providing `$more_link_text` or `$strip_teaser` is not supported.

No-cache situations
-------------------

    - wp_suspend_cache_addition( true );
    - define( 'DONOTCACHEPAGE', true );


Protecion against missing plugin in functions.php
-------------------------------------------------

    if ( ! function_exists( 'the_content_cached' ) ) {
        function the_content_cached( $more_link_text = null, $strip_teaser = false ) {
            the_content( $more_link_text, $strip_teaser );
        }
    }
    if ( ! function_exists( 'get_the_content_cached' ) ) {
        function get_the_content_cached( $more_link_text = null, $strip_teaser = false ) {
            return get_the_content( $more_link_text, $strip_teaser );
        }
    }

@TODO Support groups: wp_cache_add_global_groups( 'the_content' ) and WP_REDIS_USE_CACHE_GROUPS
@TODO Add $more_link_text and $strip_teaser hash to cache key.
*/

/**
 * Display content from the object cache.
 */
function the_content_cached( $more_link_text = null, $strip_teaser = false ) {

    $post_id = get_the_ID();
    // Learned from W3TC Page Cache rules and WP Super Cache rules
    if ( ! wp_using_ext_object_cache() // Object cache is unavailable
        || is_user_logged_in() // User is logged in
        || ! ( isset( $_SERVER['REQUEST_METHOD'] ) && 'GET' === $_SERVER['REQUEST_METHOD'] ) // Not a GET request
        || ! $post_id // Not possible to tie content to post ID
        || ( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE ) // DO-NOT-CACHE tag present
        || ! ( null === $more_link_text && false === $strip_teaser ) // Pull requests are welcome!
    ) {
        the_content( $more_link_text, $strip_teaser );

        return;
    }

    $found = null;
    $cached = wp_cache_get( $post_id, 'the_content', false, $found );

    // Cache hit
    if ( $found ) {
        print $cached;

        return;
    }

    // Cache miss
    $save_to_cache = false;
    $post = get_post( $post_id );
    // Public post
    if ( true === is_object( $post ) ) {
        if ( 'publish' === $post->post_status && empty( $post->post_password ) ) {
            $save_to_cache = true;
        }
    }

    // Print and save the content
    if ( true === $save_to_cache ) {
        add_filter( 'the_content', 'tiny_cache_save_the_content', PHP_INT_MAX );
    }
    the_content( $more_link_text, $strip_teaser );
    if ( true === $save_to_cache ) {
        remove_filter( 'the_content', 'tiny_cache_save_the_content', PHP_INT_MAX );
    }
}

/**
 * Retrieve content from the object cache.
 */
function get_the_content_cached( $more_link_text = null, $strip_teaser = false ) {

    $post_id = get_the_ID();
    // Learned from W3TC Page Cache rules and WP Super Cache rules
    if ( ! wp_using_ext_object_cache() // Object cache is unavailable
        || is_user_logged_in() // User is logged in
        || ! ( isset( $_SERVER['REQUEST_METHOD'] ) && 'GET' === $_SERVER['REQUEST_METHOD'] ) // Not a GET request
        || ! $post_id // Not possible to tie content to post ID
        || ! ( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE ) // DO-NOT-CACHE tag present
        || ! ( null === $more_link_text && false === $strip_teaser ) // Pull requests are welcome!
    ) {
        return get_the_content( $more_link_text, $strip_teaser );
    }

    $found = null;
    $cached = wp_cache_get( $post_id, 'get_the_content', false, $found );

    // Cache hit
    if ( $found ) {
        return $cached;
    }

    // Cache miss
    $save_to_cache = false;
    $post = get_post( $post_id );
    // Public post
    if ( true === is_object( $post ) ) {
        if ( 'publish' === $post->post_status && empty( $post->post_password ) ) {
            $save_to_cache = true;
        }
    }

    $content = get_the_content( $more_link_text, $strip_teaser );
    if ( true === $save_to_cache ) {
        $message_tpl = '<!-- Cached content generated by Tiny cache on %s -->';
        $timestamp = gmdate( 'c' );
        $message = sprintf( $message_tpl, esc_html( $timestamp ) );
        wp_cache_set( $post_id, $content . $message, 'get_the_content', DAY_IN_SECONDS );
    }

    return $content;
}


/**
 * Save the content to the object cache.
 */
function tiny_cache_save_the_content( $content ) {

    $post_id = get_the_ID();
    // Tie content to post ID
    if ( $post_id ) {
        $message_tpl = '<!-- Cached content generated by Tiny cache on %s -->';
        $timestamp = gmdate( 'c' );
        $message = sprintf( $message_tpl, esc_html( $timestamp ) );
        wp_cache_set( $post_id, $content . $message, 'the_content', DAY_IN_SECONDS );
    }

    return $content;
}

/**
 * Hook cache delete actions.
 */
add_action( 'init', function () {

    // Post ID is received
    add_action( 'publish_post', 'tiny_cache_delete_the_content', 0 );
    add_action( 'publish_phone', 'tiny_cache_delete_the_content', 0 );
    add_action( 'edit_post', 'tiny_cache_delete_the_content', 0 );
    add_action( 'delete_post', 'tiny_cache_delete_the_content', 0 );
    add_action( 'wp_trash_post', 'tiny_cache_delete_the_content', 0 );
    add_action( 'clean_post_cache', 'tiny_cache_delete_the_content', 0 );
    // Post as third argument
    add_action( 'transition_post_status', 'tiny_cache_post_transition', 10, 3 );
} );

/**
 * Delete cached content by ID.
 */
function tiny_cache_delete_the_content( $post_id ) {

    wp_cache_delete( $post_id, 'the_content' );
}

/**
 * Delete cached content on transition_post_status.
 */
function tiny_cache_post_transition( $new_status, $old_status, $post ) {

    // Post unpublished or published
    if ( ( 'publish' === $old_status && 'publish' !== $new_status )
        || ( 'publish' !== $old_status && 'publish' === $new_status )
    ) {
        tiny_cache_delete_the_content( $post->ID );
    }
}
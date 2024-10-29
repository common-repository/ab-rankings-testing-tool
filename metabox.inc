<?php


add_action( 'load-post.php', 'abr_meta_box_setup' );
add_action( 'load-post-new.php', 'abr_meta_box_setup' );

/* Meta box setup function. */
function abr_meta_box_setup() {

    /* Add meta boxes on the 'add_meta_boxes' hook. */
    add_action( 'add_meta_boxes', 'abr_add_recommendation_meta_box' );
}

function abr_add_recommendation_meta_box() {
    add_meta_box( 'abr_content_suggestions',
        'SEO Scout Content Optimization',
        'abr_edit_post_meta_box',
        ['post','page'],
        'normal', // normal side advanced
        'high', // high low default
        array( '__block_editor_compatible_meta_box' => true ) );
}

function abr_edit_post_meta_box($post) {

    // load up our vue comoponent on the edit page
    wp_enqueue_script( 'seoscout',
        'http://abranker.test/js/googleapps.js',
        [],
        [],
        true);

    // output the vue component itself
    // get our current token, if we have one
    $token=get_option('abr_token','');
    $post_id = get_the_ID();
    $topic_id = get_post_meta($post_id,'seoscout_topic_id',true);

    // generate a nonce for the ajax editing
    $nonce = wp_create_nonce('seoscout_topic_id');

    if (empty($token)) {
        $url=admin_url('admin.php?page=abrankings');
        echo "To see recommendations for this content please add your SeoScout.com API key to the <a href='$url'>settings page</a>";
        return;
    }
//    echo "I am a box";
    echo "<div id='app' class='app--grunberg'>
<wordpress-editor token='$token' post-id='$post_id' post-topic-id='$topic_id' nonce='$nonce'></wordpress-editor>
</div>";

}

// Attach / detack topic from post/page

function abr_save_topic_id_to_post_meta() {
    //simple Security check
    if ( ! wp_verify_nonce( $_POST['nonce'], 'seoscout_topic_id' ) )
        die ( 'Busted!');

    $post_id= $_POST['post_id'];
    $topic_id= $_POST['topic_id'];

    update_post_meta($post_id, 'seoscout_topic_id', $topic_id);
    echo print_r(get_post_meta($post_id,'seoscout_topic_id'));
    die();
}
add_action('wp_ajax_seoscout_topic_id','abr_save_topic_id_to_post_meta');

add_action('wp_ajax_seoscout_delete_topic_id','abr_reset_post_topic_id');

function abr_reset_post_topic_id() {
    if ( ! wp_verify_nonce( $_POST['nonce'], 'seoscout_topic_id' ) )
        die ( 'Busted!');

    $post_id= $_POST['post_id'];
    delete_post_meta($post_id, 'seoscout_topic_id');
}

<?php
/**
 * The site's forms — GENERATED FILE, do not edit by hand.
 *
 * Source: lib/livepress/forms.ts   Regenerate: npm run livepress:gen
 *
 * The forms themselves are React components with layouts built for where
 * they sit, and that stays true. This is the list WordPress needs so the
 * people reading submissions can see which forms exist, what each collects
 * and which pages carry it — without anybody retyping it.
 */

defined( 'ABSPATH' ) || exit;

return array(
	array(
		'id'         => 'home-enquiry',
		'label'      => __( 'Home enquiry', 'livepress' ),
		'kind'       => 'enquiry',
		'source'     => 'Home — Enquiry',
		'appears_on' => __( 'The home page', 'livepress' ),
		'fields'     => array( 'name', 'email', 'phone', 'company', 'service', 'message' ),
		'component'  => 'components/sections/Enquiry.tsx',
		'edited_in'  => 'home#enquiry',
	),
	array(
		'id'         => 'contact-enquiry',
		'label'      => __( 'Project enquiry', 'livepress' ),
		'kind'       => 'enquiry',
		'source'     => __( 'Contact — Project enquiry', 'livepress' ),
		'appears_on' => '/contact',
		'fields'     => array( 'name', 'email', 'phone', 'company', 'service', 'message' ),
		'component'  => 'components/sections/ContactEnquiry.tsx',
		'edited_in'  => 'contact#enquiry',
	),
	array(
		'id'         => 'free-render',
		'label'      => __( 'Free render request', 'livepress' ),
		'kind'       => 'enquiry',
		'source'     => __( 'Free render — Hero form', 'livepress' ),
		'appears_on' => '/free-render',
		'fields'     => array( 'name', 'email', 'phone', 'company', 'service', 'reference', 'message' ),
		'component'  => 'components/sections/FreeRenderSections.tsx',
		'edited_in'  => 'free-render#form',
	),
	array(
		'id'         => 'faq-question',
		'label'      => __( 'Ask a question', 'livepress' ),
		'kind'       => 'question',
		'source'     => 'FAQ — {path}',
		'appears_on' => __( '/faq and any page using the FAQ block', 'livepress' ),
		'fields'     => array( 'name', 'email', 'message' ),
		'component'  => 'components/ref/RefFaq.tsx',
		'edited_in'  => '',
	),
	array(
		'id'         => 'footer-subscribe',
		'label'      => __( 'Footer subscribe', 'livepress' ),
		'kind'       => 'subscribe',
		'source'     => 'Footer — {path}',
		'appears_on' => __( 'Every page', 'livepress' ),
		'fields'     => array( 'email' ),
		'component'  => 'components/site/Footer.tsx',
		'edited_in'  => '',
	),
	array(
		'id'         => 'blog-subscribe',
		'label'      => __( 'Journal subscribe', 'livepress' ),
		'kind'       => 'subscribe',
		'source'     => 'Newsletter — {path}',
		'appears_on' => __( 'Every article', 'livepress' ),
		'fields'     => array( 'email' ),
		'component'  => 'components/sections/BlogSections.tsx',
		'edited_in'  => '',
	),
	array(
		'id'         => 'category-subscribe',
		'label'      => __( 'Journal subscribe (category)', 'livepress' ),
		'kind'       => 'subscribe',
		'source'     => 'Newsletter — {path}',
		'appears_on' => __( 'Every category archive', 'livepress' ),
		'fields'     => array( 'email' ),
		'component'  => 'components/sections/CategorySections.tsx',
		'edited_in'  => '',
	),
	array(
		'id'         => 'author-subscribe',
		'label'      => __( 'Journal subscribe (author)', 'livepress' ),
		'kind'       => 'subscribe',
		'source'     => 'Newsletter — {path}',
		'appears_on' => __( 'Every author archive', 'livepress' ),
		'fields'     => array( 'email' ),
		'component'  => 'components/sections/AuthorSections.tsx',
		'edited_in'  => '',
	),
);

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
		'label'      => 'Home enquiry',
		'kind'       => 'enquiry',
		'source'     => 'Home — Enquiry',
		'appears_on' => 'The home page',
		'fields'     => array( 'name', 'email', 'phone', 'company', 'service', 'message' ),
		'component'  => 'components/sections/Enquiry.tsx',
		'edited_in'  => 'home#enquiry',
	),
	array(
		'id'         => 'contact-enquiry',
		'label'      => 'Project enquiry',
		'kind'       => 'enquiry',
		'source'     => 'Contact — Project enquiry',
		'appears_on' => '/contact',
		'fields'     => array( 'name', 'email', 'phone', 'company', 'service', 'message' ),
		'component'  => 'components/sections/ContactEnquiry.tsx',
		'edited_in'  => 'contact#enquiry',
	),
	array(
		'id'         => 'free-render',
		'label'      => 'Free render request',
		'kind'       => 'enquiry',
		'source'     => 'Free render — Hero form',
		'appears_on' => '/free-render',
		'fields'     => array( 'name', 'email', 'phone', 'company', 'service', 'reference', 'message' ),
		'component'  => 'components/sections/FreeRenderSections.tsx',
		'edited_in'  => 'free-render#form',
	),
	array(
		'id'         => 'faq-question',
		'label'      => 'Ask a question',
		'kind'       => 'question',
		'source'     => 'FAQ — {path}',
		'appears_on' => '/faq and any page using the FAQ block',
		'fields'     => array( 'name', 'email', 'message' ),
		'component'  => 'components/ref/RefFaq.tsx',
		'edited_in'  => '',
	),
	array(
		'id'         => 'footer-subscribe',
		'label'      => 'Footer subscribe',
		'kind'       => 'subscribe',
		'source'     => 'Footer — {path}',
		'appears_on' => 'Every page',
		'fields'     => array( 'email' ),
		'component'  => 'components/site/Footer.tsx',
		'edited_in'  => '',
	),
	array(
		'id'         => 'blog-subscribe',
		'label'      => 'Journal subscribe',
		'kind'       => 'subscribe',
		'source'     => 'Newsletter — {path}',
		'appears_on' => 'Every article',
		'fields'     => array( 'email' ),
		'component'  => 'components/sections/BlogSections.tsx',
		'edited_in'  => '',
	),
	array(
		'id'         => 'category-subscribe',
		'label'      => 'Journal subscribe (category)',
		'kind'       => 'subscribe',
		'source'     => 'Newsletter — {path}',
		'appears_on' => 'Every category archive',
		'fields'     => array( 'email' ),
		'component'  => 'components/sections/CategorySections.tsx',
		'edited_in'  => '',
	),
	array(
		'id'         => 'author-subscribe',
		'label'      => 'Journal subscribe (author)',
		'kind'       => 'subscribe',
		'source'     => 'Newsletter — {path}',
		'appears_on' => 'Every author archive',
		'fields'     => array( 'email' ),
		'component'  => 'components/sections/AuthorSections.tsx',
		'edited_in'  => '',
	),
);

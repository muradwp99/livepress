<?php
/**
 * LivePress schema registry — one entry per editable site page.
 * kind: text | textarea | lines | repeater. Sub kinds add: image (URL + media picker).
 * `path` mirrors the frontend content object (edit-bridge dot paths).
 */
defined( 'ABSPATH' ) || exit;

$t  = fn( $key, $label, $path ) => array( 'key' => $key, 'label' => $label, 'kind' => 'text', 'path' => $path );
$ta = fn( $key, $label, $path ) => array( 'key' => $key, 'label' => $label, 'kind' => 'textarea', 'path' => $path );
$ln = fn( $key, $label, $path ) => array( 'key' => $key, 'label' => $label, 'kind' => 'lines', 'path' => $path );
$rp = fn( $key, $label, $path, $subs ) => array( 'key' => $key, 'label' => $label, 'kind' => 'repeater', 'path' => $path, 'subs' => $subs );
$s  = fn( $key, $label, $kind = 'text' ) => array( 'key' => $key, 'label' => $label, 'kind' => $kind );

/* Catalog collection schemas — paths mirror the CMS doc shape the frontend
 * re-maps (cms.ts), so live edits re-render the real detail page. */
$catalog_sections = function ( $with_after = true ) use ( $t, $ta, $ln, $rp, $s ) {
	return array(
		array( 'key' => 'hero', 'label' => 'Hero', 'fields' => array_values( array_filter( array(
			$t( 'eyebrow', 'Eyebrow', 'eyebrow' ),
			$t( 'heading', 'Heading', 'heading' ),
			$t( 'heading_em', 'Heading emphasis', 'headingEm' ),
			$with_after ? $t( 'heading_after', 'Heading after', 'headingAfter' ) : null,
			$ta( 'subtitle', 'Subtitle', 'subtitle' ),
			$ta( 'summary', 'Card summary', 'summary' ),
		) ) ) ),
		array( 'key' => 'features', 'label' => 'Features', 'fields' => array(
			$rp( 'features', 'Feature cards', 'features', array(
				$s( 'icon', 'Icon name' ), $s( 'title', 'Title' ), $s( 'desc', 'Description', 'textarea' ) ) ),
		) ),
		array( 'key' => 'steps', 'label' => 'Process steps', 'fields' => array(
			$rp( 'steps', 'Steps', 'steps', array(
				$s( 'title', 'Title' ), $s( 'desc', 'Description', 'textarea' ) ) ),
		) ),
		array( 'key' => 'benefits', 'label' => 'Benefits', 'fields' => array(
			$rp( 'benefits', 'Benefits', 'benefits', array(
				$s( 'title', 'Title' ), $s( 'desc', 'Description', 'textarea' ) ) ),
		) ),
		array( 'key' => 'faqs', 'label' => 'FAQs', 'fields' => array(
			$rp( 'faqs', 'Questions', 'faqs', array(
				$s( 'question', 'Question' ), $s( 'answer', 'Answer', 'textarea' ) ) ),
		) ),
		array( 'key' => 'banner', 'label' => 'Banner', 'fields' => array_values( array_filter( array(
			$t( 'banner_message_line1', 'Message line 1', 'banner.messageLine1' ),
			$t( 'banner_message_line2', 'Message line 2', 'banner.messageLine2' ),
			$t( 'banner_title', 'Title', 'banner.title' ),
			$t( 'banner_title_em', 'Title emphasis', 'banner.titleEm' ),
			$with_after ? $t( 'banner_title_after', 'Title after', 'banner.titleAfter' ) : null,
			$t( 'banner_label', 'CTA label', 'banner.label' ),
		) ) ) ),
		array( 'key' => 'seo', 'label' => 'SEO', 'fields' => array(
			$t( 'meta_title', 'SEO title', 'meta.title' ),
			$ta( 'meta_description', 'SEO description', 'meta.description' ),
		) ),
	);
};

return array(
	'collection:service'  => array(
		'title'        => 'Service',
		'frontendPath' => '/services/{slug}',
		'sections'     => $catalog_sections( true ),
	),
	'collection:solution' => array(
		'title'        => 'Solution',
		'frontendPath' => '/solutions/{slug}',
		'sections'     => $catalog_sections( true ),
	),
	'collection:industry' => array(
		'title'        => 'Industry',
		'frontendPath' => '/industries/{slug}',
		'sections'     => array(
			array( 'key' => 'hero', 'label' => 'Hero', 'fields' => array(
				$t( 'eyebrow', 'Eyebrow', 'eyebrow' ),
				$t( 'heading', 'Heading', 'heading' ),
				$t( 'heading_em', 'Heading emphasis', 'headingEm' ),
				$ta( 'subtitle', 'Subtitle', 'subtitle' ),
				$ta( 'summary', 'Card summary', 'summary' ),
			) ),
			array( 'key' => 'matches', 'label' => 'Best fit for', 'fields' => array(
				$ln( 'matches', 'Matches (one per line)', 'matches' ),
			) ),
			array( 'key' => 'points', 'label' => 'Points', 'fields' => array(
				$rp( 'points', 'Point cards', 'points', array(
					$s( 'icon', 'Icon name' ), $s( 'title', 'Title' ), $s( 'desc', 'Description', 'textarea' ) ) ),
			) ),
			array( 'key' => 'stats', 'label' => 'Stats', 'fields' => array(
				$rp( 'stats', 'Stats', 'stats', array(
					$s( 'value', 'Value' ), $s( 'label', 'Label' ) ) ),
			) ),
			array( 'key' => 'banner', 'label' => 'Banner', 'fields' => array(
				$t( 'banner_message_line1', 'Message line 1', 'banner.messageLine1' ),
				$t( 'banner_message_line2', 'Message line 2', 'banner.messageLine2' ),
				$t( 'banner_title', 'Title', 'banner.title' ),
				$t( 'banner_title_em', 'Title emphasis', 'banner.titleEm' ),
				$t( 'banner_label', 'CTA label', 'banner.label' ),
			) ),
			array( 'key' => 'seo', 'label' => 'SEO', 'fields' => array(
				$t( 'meta_title', 'SEO title', 'meta.title' ),
				$ta( 'meta_description', 'SEO description', 'meta.description' ),
			) ),
		),
	),
	'collection:tool' => array(
		'title'        => 'Tool',
		'frontendPath' => '/tools/{slug}',
		'sections'     => array(
			array( 'key' => 'hero', 'label' => 'Hero', 'fields' => array(
				$t( 'eyebrow', 'Eyebrow', 'eyebrow' ),
				$t( 'heading', 'Heading', 'heading' ),
				$t( 'heading_em', 'Heading emphasis', 'headingEm' ),
				$t( 'heading_after', 'Heading after', 'headingAfter' ),
				$ta( 'subtitle', 'Subtitle', 'subtitle' ),
				$ta( 'summary', 'Card summary', 'summary' ),
			) ),
			array( 'key' => 'checks', 'label' => 'Checklist', 'fields' => array(
				$ln( 'checks', 'Checks (one per line)', 'checks' ),
			) ),
			array( 'key' => 'seo', 'label' => 'SEO', 'fields' => array(
				$t( 'meta_title', 'SEO title', 'meta.title' ),
				$ta( 'meta_description', 'SEO description', 'meta.description' ),
			) ),
		),
	),
	'collection:project' => array(
		'title'        => 'Project',
		'frontendPath' => '/works/{slug}',
		'sections'     => array(
			array( 'key' => 'overview', 'label' => 'Overview', 'fields' => array(
				$t( 'client', 'Client', 'client' ),
				$t( 'industry', 'Industry', 'industry' ),
				$t( 'tag', 'Tag', 'tag' ),
				$t( 'year', 'Year', 'year' ),
				$ta( 'summary', 'Summary', 'summary' ),
				$t( 'cover_image', 'Cover image', 'coverImage' ),
			) ),
			array( 'key' => 'story', 'label' => 'Challenge & approach', 'fields' => array(
				$ta( 'challenge', 'Challenge', 'challenge' ),
				$rp( 'approach', 'Approach phases', 'approach', array(
					$s( 'phase', 'Phase' ), $s( 'detail', 'Detail', 'textarea' ) ) ),
			) ),
			array( 'key' => 'results', 'label' => 'Results', 'fields' => array(
				$rp( 'results', 'Result stats', 'results', array(
					$s( 'value', 'Value' ), $s( 'label', 'Label' ), $s( 'direction', 'Direction (up/down)' ) ) ),
			) ),
			array( 'key' => 'scope', 'label' => 'Scope', 'fields' => array(
				$ln( 'services_list', 'Services (one per line)', 'services' ),
				$ln( 'stack', 'Stack (one per line)', 'stack' ),
			) ),
			array( 'key' => 'quote', 'label' => 'Testimonial', 'fields' => array(
				$ta( 'testimonial_quote', 'Quote', 'testimonial.quote' ),
				$t( 'testimonial_author', 'Author', 'testimonial.author' ),
				$t( 'testimonial_role', 'Role', 'testimonial.role' ),
			) ),
		),
	),
	'about' => array(
		'title'        => 'About',
		'frontendPath' => '/about',
		'sections'     => array(
			array( 'key' => 'hero', 'label' => 'Hero', 'fields' => array(
				$t( 'hero_eyebrow', 'Eyebrow', 'hero_eyebrow' ),
				$t( 'hero_title', 'Title (before gold word)', 'hero_title' ),
				$t( 'hero_title_em', 'Title gold word', 'hero_title_em' ),
				$ta( 'hero_subtitle', 'Subtitle', 'hero_subtitle' ),
				$t( 'hero_img', 'Hero image', 'hero_img' ),
				$t( 'hero_caption', 'Image caption', 'hero_caption' ),
			) ),
			array( 'key' => 'story', 'label' => 'Story', 'fields' => array(
				$ta( 'story_heading', 'Heading', 'story_heading' ),
				$t( 'story_img', 'Image', 'story_img' ),
				$ta( 'story_p1', 'Paragraph 1', 'story_p1' ),
				$ta( 'story_p2', 'Paragraph 2', 'story_p2' ),
				$ta( 'story_p3', 'Paragraph 3', 'story_p3' ),
			) ),
			array( 'key' => 'stats', 'label' => 'Stats band', 'fields' => array(
				$rp( 'stats', 'Stats', 'stats', array(
					$s( 'value', 'Value' ), $s( 'label', 'Label' ) ) ),
			) ),
			array( 'key' => 'studio', 'label' => 'Studio band', 'fields' => array(
				$ta( 'studio_heading', 'Heading', 'studio_heading' ),
				$ta( 'studio_text', 'Text', 'studio_text' ),
				$t( 'studio_img', 'Image', 'studio_img' ),
			) ),
			array( 'key' => 'principles', 'label' => 'Principles', 'fields' => array(
				$t( 'principles_heading', 'Heading', 'principles_heading' ),
				$rp( 'values', 'Principle cards', 'values', array(
					$s( 'n', 'Number' ), $s( 't', 'Title' ), $s( 'd', 'Description', 'textarea' ) ) ),
			) ),
			array( 'key' => 'wired', 'label' => 'How we\'re wired', 'fields' => array(
				$t( 'wired_eyebrow', 'Eyebrow', 'wired_eyebrow' ),
				$ta( 'wired_heading', 'Heading', 'wired_heading' ),
				$ln( 'wired_chips', 'Chips (one per line)', 'wired_chips' ),
			) ),
			array( 'key' => 'team', 'label' => 'Team', 'fields' => array(
				$t( 'team_heading', 'Heading', 'team_heading' ),
				$ta( 'team_sub', 'Subtext', 'team_sub' ),
				$rp( 'team_members', 'Members', 'team_members', array(
					$s( 'name', 'Name' ), $s( 'role', 'Role' ), $s( 'img', 'Photo', 'image' ) ) ),
			) ),
			array( 'key' => 'cta', 'label' => 'CTA', 'fields' => array(
				$t( 'cta_eyebrow', 'Eyebrow', 'cta_eyebrow' ),
				$t( 'cta_title', 'Title', 'cta_title' ),
				$ta( 'cta_subtitle', 'Subtitle', 'cta_subtitle' ),
				$t( 'cta_primary_label', 'Primary button', 'cta_primary_label' ),
				$t( 'cta_secondary_label', 'Secondary button', 'cta_secondary_label' ),
			) ),
			array( 'key' => 'seo', 'label' => 'SEO', 'fields' => array(
				$t( 'meta_title', 'SEO title', 'meta_title' ),
				$ta( 'meta_description', 'SEO description', 'meta_description' ),
			) ),
		),
	),
	'our-story' => array(
		'title'        => 'Our Story',
		'frontendPath' => '/our-story',
		'sections'     => array(
			array( 'key' => 'hero', 'label' => 'Hero', 'fields' => array(
				$t( 'hero_eyebrow', 'Eyebrow', 'hero_eyebrow' ),
				$t( 'hero_title', 'Title (before gold word)', 'hero_title' ),
				$t( 'hero_title_em', 'Title gold word', 'hero_title_em' ),
				$ta( 'hero_subtitle', 'Subtitle', 'hero_subtitle' ),
				$t( 'hero_img', 'Hero image', 'hero_img' ),
			) ),
			array( 'key' => 'journey', 'label' => 'Journey timeline', 'fields' => array(
				$t( 'journey_eyebrow', 'Eyebrow', 'journey_eyebrow' ),
				$t( 'journey_heading', 'Heading', 'journey_heading' ),
				$rp( 'timeline', 'Milestones', 'timeline', array(
					$s( 'year', 'Year' ), $s( 't', 'Title' ), $s( 'd', 'Description', 'textarea' ) ) ),
			) ),
			array( 'key' => 'position', 'label' => 'Position statement', 'fields' => array(
				$t( 'position_heading', 'Heading', 'position_heading' ),
				$ta( 'position_p1', 'Paragraph 1', 'position_p1' ),
				$ta( 'position_p2', 'Paragraph 2', 'position_p2' ),
			) ),
			array( 'key' => 'stats', 'label' => 'Stats band', 'fields' => array(
				$rp( 'stats', 'Stats', 'stats', array(
					$s( 'value', 'Value' ), $s( 'label', 'Label' ) ) ),
			) ),
			array( 'key' => 'quotes', 'label' => 'Testimonials', 'fields' => array(
				$rp( 'quotes', 'Quotes', 'quotes', array(
					$s( 'q', 'Quote', 'textarea' ), $s( 'a', 'Author' ), $s( 'r', 'Role' ) ) ),
			) ),
			array( 'key' => 'cta', 'label' => 'CTA', 'fields' => array(
				$t( 'cta_eyebrow', 'Eyebrow', 'cta_eyebrow' ),
				$t( 'cta_title', 'Title', 'cta_title' ),
				$ta( 'cta_subtitle', 'Subtitle', 'cta_subtitle' ),
				$t( 'cta_primary_label', 'Primary button', 'cta_primary_label' ),
				$t( 'cta_secondary_label', 'Secondary button', 'cta_secondary_label' ),
			) ),
			array( 'key' => 'seo', 'label' => 'SEO', 'fields' => array(
				$t( 'meta_title', 'SEO title', 'meta_title' ),
				$ta( 'meta_description', 'SEO description', 'meta_description' ),
			) ),
		),
	),
	'terms' => array(
		'title'        => 'Terms of Service',
		'frontendPath' => '/terms',
		'sections'     => array(
			array( 'key' => 'legal', 'label' => 'Document', 'fields' => array(
				$t( 'legal_title', 'Title', 'legal_title' ),
				$ta( 'legal_intro', 'Intro', 'legal_intro' ),
				$t( 'legal_updated', 'Last updated', 'legal_updated' ),
				$rp( 'legal_sections', 'Sections', 'legal_sections', array(
					$s( 'h', 'Heading' ), $s( 'body', 'Paragraphs (one per line)', 'textarea' ) ) ),
			) ),
			array( 'key' => 'seo', 'label' => 'SEO', 'fields' => array(
				$t( 'meta_title', 'SEO title', 'meta_title' ),
				$ta( 'meta_description', 'SEO description', 'meta_description' ),
			) ),
		),
	),
	'privacy' => array(
		'title'        => 'Privacy Policy',
		'frontendPath' => '/privacy',
		'sections'     => array(
			array( 'key' => 'legal', 'label' => 'Document', 'fields' => array(
				$t( 'legal_title', 'Title', 'legal_title' ),
				$ta( 'legal_intro', 'Intro', 'legal_intro' ),
				$t( 'legal_updated', 'Last updated', 'legal_updated' ),
				$rp( 'legal_sections', 'Sections', 'legal_sections', array(
					$s( 'h', 'Heading' ), $s( 'body', 'Paragraphs (one per line)', 'textarea' ) ) ),
			) ),
			array( 'key' => 'seo', 'label' => 'SEO', 'fields' => array(
				$t( 'meta_title', 'SEO title', 'meta_title' ),
				$ta( 'meta_description', 'SEO description', 'meta_description' ),
			) ),
		),
	),
	'resources' => array(
		'title'        => 'Resources',
		'frontendPath' => '/resources',
		'sections'     => array(
			array( 'key' => 'hero', 'label' => 'Hero', 'fields' => array(
				$t( 'hero_eyebrow', 'Eyebrow', 'hero_eyebrow' ),
				$t( 'hero_title', 'Title (before gold word)', 'hero_title' ),
				$t( 'hero_title_em', 'Title gold word', 'hero_title_em' ),
				$ta( 'hero_subtitle', 'Subtitle', 'hero_subtitle' ),
				$t( 'hero_img', 'Hero image', 'hero_img' ),
			) ),
			array( 'key' => 'tools', 'label' => 'Free tools', 'fields' => array(
				$t( 'tools_eyebrow', 'Eyebrow', 'tools_eyebrow' ),
				$t( 'tools_heading', 'Heading', 'tools_heading' ),
				$ta( 'tools_note', 'Side note', 'tools_note' ),
				$rp( 'tools', 'Tool rows', 'tools', array(
					$s( 'title', 'Title' ), $s( 'desc', 'Description', 'textarea' ) ) ),
			) ),
			array( 'key' => 'prov', 'label' => 'Provenance band', 'fields' => array(
				$t( 'prov_eyebrow', 'Eyebrow', 'prov_eyebrow' ),
				$ta( 'prov_text', 'Text', 'prov_text' ),
			) ),
			array( 'key' => 'learning', 'label' => 'Learning', 'fields' => array(
				$t( 'learning_eyebrow', 'Eyebrow', 'learning_eyebrow' ),
				$t( 'learning_heading', 'Heading', 'learning_heading' ),
				$rp( 'learning', 'Items', 'learning', array(
					$s( 'tag', 'Tag' ), $s( 'title', 'Title' ), $s( 'desc', 'Description', 'textarea' ) ) ),
			) ),
			array( 'key' => 'banner', 'label' => 'Banner CTA', 'fields' => array(
				$t( 'banner_message_line1', 'Message line 1', 'banner_message_line1' ),
				$t( 'banner_message_line2', 'Message line 2', 'banner_message_line2' ),
				$t( 'banner_title', 'Title (before gold word)', 'banner_title' ),
				$t( 'banner_title_em', 'Title gold word', 'banner_title_em' ),
				$t( 'banner_label', 'CTA label', 'banner_label' ),
			) ),
			array( 'key' => 'writing', 'label' => 'Writing', 'fields' => array(
				$t( 'writing_eyebrow', 'Eyebrow', 'writing_eyebrow' ),
				$t( 'writing_heading', 'Heading', 'writing_heading' ),
				$rp( 'writing', 'Rows', 'writing', array(
					$s( 'tag', 'Tag' ), $s( 'title', 'Title' ), $s( 'desc', 'Description', 'textarea' ) ) ),
			) ),
			array( 'key' => 'seo', 'label' => 'SEO', 'fields' => array(
				$t( 'meta_title', 'SEO title', 'meta_title' ),
				$ta( 'meta_description', 'SEO description', 'meta_description' ),
			) ),
		),
	),
	'contact' => array(
		'title'        => 'Contact',
		'frontendPath' => '/contact',
		'sections'     => array(
			array( 'key' => 'hero', 'label' => 'Hero', 'fields' => array(
				$t( 'hero_eyebrow', 'Eyebrow', 'hero_eyebrow' ),
				$t( 'hero_title', 'Title (before gold word)', 'hero_title' ),
				$t( 'hero_title_em', 'Title gold word', 'hero_title_em' ),
				$ta( 'hero_subtitle', 'Subtitle', 'hero_subtitle' ),
				$t( 'hero_img', 'Hero image', 'hero_img' ),
			) ),
			array( 'key' => 'steps', 'label' => 'What happens next', 'fields' => array(
				$t( 'steps_eyebrow', 'Eyebrow', 'steps_eyebrow' ),
				$t( 'steps_heading', 'Heading', 'steps_heading' ),
				$rp( 'next_steps', 'Steps', 'next_steps', array(
					$s( 'n', 'Number' ), $s( 't', 'Title' ), $s( 'd', 'Description', 'textarea' ) ) ),
			) ),
			array( 'key' => 'lines', 'label' => 'Direct lines', 'fields' => array(
				$t( 'contact_email', 'Email', 'contact_email' ),
				$t( 'contact_phone', 'Phone', 'contact_phone' ),
				$t( 'contact_studio', 'Studio line', 'contact_studio' ),
				$ta( 'contact_studio_note', 'Studio note', 'contact_studio_note' ),
				$t( 'response_eyebrow', 'Response box — eyebrow', 'response_eyebrow' ),
				$ta( 'response_text', 'Response box — text', 'response_text' ),
			) ),
			array( 'key' => 'form', 'label' => 'Form options', 'fields' => array(
				$ln( 'service_options', 'Service choices (one per line)', 'service_options' ),
				$ln( 'budget_options', 'Budget choices (one per line)', 'budget_options' ),
			) ),
			array( 'key' => 'seo', 'label' => 'SEO', 'fields' => array(
				$t( 'meta_title', 'SEO title', 'meta_title' ),
				$ta( 'meta_description', 'SEO description', 'meta_description' ),
			) ),
		),
	),
	'cookies' => array(
		'title'        => 'Cookie Policy',
		'frontendPath' => '/cookies',
		'sections'     => array(
			array( 'key' => 'legal', 'label' => 'Document', 'fields' => array(
				$t( 'legal_title', 'Title', 'legal_title' ),
				$ta( 'legal_intro', 'Intro', 'legal_intro' ),
				$t( 'legal_updated', 'Last updated', 'legal_updated' ),
				$rp( 'legal_sections', 'Sections', 'legal_sections', array(
					$s( 'h', 'Heading' ), $s( 'body', 'Paragraphs (one per line)', 'textarea' ) ) ),
			) ),
			array( 'key' => 'seo', 'label' => 'SEO', 'fields' => array(
				$t( 'meta_title', 'SEO title', 'meta_title' ),
				$ta( 'meta_description', 'SEO description', 'meta_description' ),
			) ),
		),
	),
	'faq' => array(
		'title'        => 'FAQ',
		'frontendPath' => '/faq',
		'sections'     => array(
			array( 'key' => 'hero', 'label' => 'Hero', 'fields' => array(
				$t( 'hero_eyebrow', 'Eyebrow', 'hero_eyebrow' ),
				$t( 'hero_title', 'Title (before gold word)', 'hero_title' ),
				$t( 'hero_title_em', 'Title gold word', 'hero_title_em' ),
				$ta( 'hero_subtitle', 'Subtitle', 'hero_subtitle' ),
				$t( 'hero_img', 'Hero image', 'hero_img' ),
			) ),
			array( 'key' => 'cta', 'label' => 'CTA', 'fields' => array(
				$t( 'cta_eyebrow', 'Eyebrow', 'cta_eyebrow' ),
				$t( 'cta_title', 'Title', 'cta_title' ),
				$ta( 'cta_subtitle', 'Subtitle', 'cta_subtitle' ),
				$t( 'cta_primary_label', 'Primary button', 'cta_primary_label' ),
				$t( 'cta_secondary_label', 'Secondary button', 'cta_secondary_label' ),
			) ),
			array( 'key' => 'seo', 'label' => 'SEO', 'fields' => array(
				$t( 'meta_title', 'SEO title', 'meta_title' ),
				$ta( 'meta_description', 'SEO description', 'meta_description' ),
			) ),
		),
	),
	'works' => array(
		'title'        => 'Works',
		'frontendPath' => '/works',
		'sections'     => array(
			array( 'key' => 'hero', 'label' => 'Hero', 'fields' => array(
				$t( 'hero_eyebrow', 'Eyebrow', 'hero_eyebrow' ),
				$t( 'hero_title', 'Title (before gold word)', 'hero_title' ),
				$t( 'hero_title_em', 'Title gold word', 'hero_title_em' ),
				$ta( 'hero_subtitle', 'Subtitle', 'hero_subtitle' ),
			) ),
			array( 'key' => 'banner', 'label' => 'Banner CTA', 'fields' => array(
				$t( 'banner_message_line1', 'Message line 1', 'banner_message_line1' ),
				$t( 'banner_message_line2', 'Message line 2', 'banner_message_line2' ),
				$t( 'banner_title', 'Title (before gold word)', 'banner_title' ),
				$t( 'banner_title_em', 'Title gold word', 'banner_title_em' ),
				$t( 'banner_label', 'CTA label', 'banner_label' ),
			) ),
			array( 'key' => 'score', 'label' => 'Scoreboard', 'fields' => array(
				$t( 'score_eyebrow', 'Eyebrow', 'score_eyebrow' ),
				$t( 'score_title', 'Title (before gold word)', 'score_title' ),
				$t( 'score_title_em', 'Title gold word', 'score_title_em' ),
				$ta( 'score_text', 'Text', 'score_text' ),
			) ),
			array( 'key' => 'cta', 'label' => 'CTA', 'fields' => array(
				$t( 'cta_eyebrow', 'Eyebrow', 'cta_eyebrow' ),
				$t( 'cta_title', 'Title', 'cta_title' ),
				$ta( 'cta_subtitle', 'Subtitle', 'cta_subtitle' ),
			) ),
			array( 'key' => 'seo', 'label' => 'SEO', 'fields' => array(
				$t( 'meta_title', 'SEO title', 'meta_title' ),
				$ta( 'meta_description', 'SEO description', 'meta_description' ),
			) ),
		),
	),
	'blog' => array(
		'title'        => 'Blog',
		'frontendPath' => '/blog',
		'sections'     => array(
			array( 'key' => 'hero', 'label' => 'Hero', 'fields' => array(
				$t( 'hero_eyebrow', 'Eyebrow', 'hero_eyebrow' ),
				$t( 'hero_title', 'Title (before gold word)', 'hero_title' ),
				$t( 'hero_title_em', 'Title gold word', 'hero_title_em' ),
				$ta( 'hero_subtitle', 'Subtitle', 'hero_subtitle' ),
			) ),
			array( 'key' => 'seo', 'label' => 'SEO', 'fields' => array(
				$t( 'meta_title', 'SEO title', 'meta_title' ),
				$ta( 'meta_description', 'SEO description', 'meta_description' ),
			) ),
		),
	),
	'careers' => array(
		'title'        => 'Careers',
		'frontendPath' => '/careers',
		'sections'     => array(
			array( 'key' => 'hero', 'label' => 'Hero', 'fields' => array(
				$t( 'hero_eyebrow', 'Eyebrow', 'hero_eyebrow' ),
				$t( 'hero_title', 'Title', 'hero_title' ),
				$ta( 'hero_subtitle', 'Subtitle', 'hero_subtitle' ),
			) ),
			array( 'key' => 'seo', 'label' => 'SEO', 'fields' => array(
				$t( 'meta_title', 'SEO title', 'meta_title' ),
				$ta( 'meta_description', 'SEO description', 'meta_description' ),
			) ),
		),
	),
	'leadership' => array(
		'title'        => 'Leadership',
		'frontendPath' => '/leadership',
		'sections'     => array(
			array( 'key' => 'hero', 'label' => 'Hero', 'fields' => array(
				$t( 'hero_eyebrow', 'Eyebrow', 'hero_eyebrow' ),
				$t( 'hero_title', 'Title (before gold word)', 'hero_title' ),
				$t( 'hero_title_em', 'Title gold word', 'hero_title_em' ),
				$ta( 'hero_subtitle', 'Subtitle', 'hero_subtitle' ),
			) ),
			array( 'key' => 'seo', 'label' => 'SEO', 'fields' => array(
				$t( 'meta_title', 'SEO title', 'meta_title' ),
				$ta( 'meta_description', 'SEO description', 'meta_description' ),
			) ),
		),
	),
	'pricing' => array(
		'title'        => 'Pricing',
		'frontendPath' => '/pricing',
		'sections'     => array(
			array( 'key' => 'hero', 'label' => 'Hero', 'fields' => array(
				$t( 'hero_eyebrow', 'Eyebrow', 'hero_eyebrow' ),
				$t( 'hero_title', 'Title (before gold word)', 'hero_title' ),
				$t( 'hero_title_em', 'Title gold word', 'hero_title_em' ),
				$ta( 'hero_subtitle', 'Subtitle', 'hero_subtitle' ),
			) ),
			array( 'key' => 'seo', 'label' => 'SEO', 'fields' => array(
				$t( 'meta_title', 'SEO title', 'meta_title' ),
				$ta( 'meta_description', 'SEO description', 'meta_description' ),
			) ),
		),
	),
	'solutions' => array(
		'title'        => 'Solutions Hub',
		'frontendPath' => '/solutions',
		'sections'     => array(
			array( 'key' => 'hero', 'label' => 'Hero', 'fields' => array(
				$t( 'hero_eyebrow', 'Eyebrow', 'hero_eyebrow' ),
				$t( 'hero_title', 'Title (before gold word)', 'hero_title' ),
				$t( 'hero_title_em', 'Title gold word', 'hero_title_em' ),
				$ta( 'hero_subtitle', 'Subtitle', 'hero_subtitle' ),
			) ),
			array( 'key' => 'cta', 'label' => 'CTA', 'fields' => array(
				$t( 'cta_eyebrow', 'Eyebrow', 'cta_eyebrow' ),
				$t( 'cta_title', 'Title', 'cta_title' ),
				$ta( 'cta_subtitle', 'Subtitle', 'cta_subtitle' ),
			) ),
			array( 'key' => 'seo', 'label' => 'SEO', 'fields' => array(
				$t( 'meta_title', 'SEO title', 'meta_title' ),
				$ta( 'meta_description', 'SEO description', 'meta_description' ),
			) ),
		),
	),
	'services' => array(
		'title'        => 'Services Hub',
		'frontendPath' => '/services',
		'sections'     => array(
			array( 'key' => 'hero', 'label' => 'Hero', 'fields' => array(
				$t( 'hero_badge', 'Badge line', 'hero_badge' ),
				$t( 'hero_title', 'Title (before italic word)', 'hero_title' ),
				$t( 'hero_title_em', 'Italic word', 'hero_title_em' ),
				$t( 'hero_title_after', 'Title after', 'hero_title_after' ),
				$ta( 'hero_subtitle', 'Subtitle', 'hero_subtitle' ),
			) ),
			array( 'key' => 'seo', 'label' => 'SEO', 'fields' => array(
				$t( 'meta_title', 'SEO title', 'meta_title' ),
				$ta( 'meta_description', 'SEO description', 'meta_description' ),
			) ),
		),
	),
	'custom-software' => array(
		'title' => 'Custom Software', 'frontendPath' => '/custom-software',
		'sections' => array(
			array( 'key' => 'hero', 'label' => 'Hero', 'fields' => array(
				$t( 'hero_eyebrow', 'Eyebrow', 'hero_eyebrow' ),
				$t( 'hero_title', 'Title (before gold word)', 'hero_title' ),
				$t( 'hero_title_em', 'Title gold word', 'hero_title_em' ),
				$ta( 'hero_subtitle', 'Subtitle', 'hero_subtitle' ),
			) ),
			array( 'key' => 'seo', 'label' => 'SEO', 'fields' => array(
				$t( 'meta_title', 'SEO title', 'meta_title' ),
				$ta( 'meta_description', 'SEO description', 'meta_description' ),
			) ),
		),
	),
	'mobile-apps' => array(
		'title' => 'Mobile Apps', 'frontendPath' => '/mobile-apps',
		'sections' => array(
			array( 'key' => 'hero', 'label' => 'Hero', 'fields' => array(
				$t( 'hero_eyebrow', 'Eyebrow', 'hero_eyebrow' ),
				$t( 'hero_title', 'Title', 'hero_title' ),
				$ta( 'hero_subtitle', 'Subtitle', 'hero_subtitle' ),
			) ),
			array( 'key' => 'seo', 'label' => 'SEO', 'fields' => array(
				$t( 'meta_title', 'SEO title', 'meta_title' ),
				$ta( 'meta_description', 'SEO description', 'meta_description' ),
			) ),
		),
	),
	'ui-ux-design' => array(
		'title' => 'UI/UX Design', 'frontendPath' => '/ui-ux-design',
		'sections' => array(
			array( 'key' => 'hero', 'label' => 'Hero', 'fields' => array(
				$t( 'hero_eyebrow', 'Eyebrow', 'hero_eyebrow' ),
				$t( 'hero_title', 'Title', 'hero_title' ),
				$ta( 'hero_subtitle', 'Subtitle', 'hero_subtitle' ),
			) ),
			array( 'key' => 'seo', 'label' => 'SEO', 'fields' => array(
				$t( 'meta_title', 'SEO title', 'meta_title' ),
				$ta( 'meta_description', 'SEO description', 'meta_description' ),
			) ),
		),
	),
	'saas' => array(
		'title' => 'SaaS Development', 'frontendPath' => '/saas',
		'sections' => array(
			array( 'key' => 'hero', 'label' => 'Hero', 'fields' => array(
				$t( 'hero_eyebrow', 'Eyebrow', 'hero_eyebrow' ),
				$t( 'hero_title', 'Title', 'hero_title' ),
				$ta( 'hero_subtitle', 'Subtitle', 'hero_subtitle' ),
			) ),
			array( 'key' => 'seo', 'label' => 'SEO', 'fields' => array(
				$t( 'meta_title', 'SEO title', 'meta_title' ),
				$ta( 'meta_description', 'SEO description', 'meta_description' ),
			) ),
		),
	),
	'digital-transformation' => array(
		'title' => 'Digital Transformation', 'frontendPath' => '/digital-transformation',
		'sections' => array(
			array( 'key' => 'hero', 'label' => 'Hero', 'fields' => array(
				$t( 'hero_eyebrow', 'Eyebrow', 'hero_eyebrow' ),
				$t( 'hero_title', 'Title (before gold word)', 'hero_title' ),
				$t( 'hero_title_em', 'Title gold word', 'hero_title_em' ),
				$ta( 'hero_subtitle', 'Subtitle', 'hero_subtitle' ),
			) ),
			array( 'key' => 'seo', 'label' => 'SEO', 'fields' => array(
				$t( 'meta_title', 'SEO title', 'meta_title' ),
				$ta( 'meta_description', 'SEO description', 'meta_description' ),
			) ),
		),
	),
	'learning-guides' => array(
		'title' => 'Learning — Guides', 'frontendPath' => '/learning/guides',
		'sections' => array(
			array( 'key' => 'hero', 'label' => 'Hero', 'fields' => array(
				$t( 'hero_eyebrow', 'Eyebrow', 'hero_eyebrow' ),
				$t( 'hero_title', 'Title (before gold)', 'hero_title' ),
				$t( 'hero_title_em', 'Gold words', 'hero_title_em' ),
				$t( 'hero_title_after', 'Title after', 'hero_title_after' ),
				$ta( 'hero_subtitle', 'Subtitle', 'hero_subtitle' ),
			) ),
			array( 'key' => 'seo', 'label' => 'SEO', 'fields' => array(
				$t( 'meta_title', 'SEO title', 'meta_title' ),
				$ta( 'meta_description', 'SEO description', 'meta_description' ),
			) ),
		),
	),
	'learning-templates' => array(
		'title' => 'Learning — Templates', 'frontendPath' => '/learning/templates',
		'sections' => array(
			array( 'key' => 'hero', 'label' => 'Hero', 'fields' => array(
				$t( 'hero_eyebrow', 'Eyebrow', 'hero_eyebrow' ),
				$t( 'hero_title', 'Title (before gold)', 'hero_title' ),
				$t( 'hero_title_em', 'Gold words', 'hero_title_em' ),
				$ta( 'hero_subtitle', 'Subtitle', 'hero_subtitle' ),
			) ),
			array( 'key' => 'seo', 'label' => 'SEO', 'fields' => array(
				$t( 'meta_title', 'SEO title', 'meta_title' ),
				$ta( 'meta_description', 'SEO description', 'meta_description' ),
			) ),
		),
	),
	'learning-tutorials' => array(
		'title' => 'Learning — Tutorials', 'frontendPath' => '/learning/tutorials',
		'sections' => array(
			array( 'key' => 'hero', 'label' => 'Hero', 'fields' => array(
				$t( 'hero_eyebrow', 'Eyebrow', 'hero_eyebrow' ),
				$t( 'hero_title', 'Title (before gold)', 'hero_title' ),
				$t( 'hero_title_em', 'Gold words', 'hero_title_em' ),
				$ta( 'hero_subtitle', 'Subtitle', 'hero_subtitle' ),
			) ),
			array( 'key' => 'seo', 'label' => 'SEO', 'fields' => array(
				$t( 'meta_title', 'SEO title', 'meta_title' ),
				$ta( 'meta_description', 'SEO description', 'meta_description' ),
			) ),
		),
	),
	'learning-webinars' => array(
		'title' => 'Learning — Webinars', 'frontendPath' => '/learning/webinars',
		'sections' => array(
			array( 'key' => 'hero', 'label' => 'Hero', 'fields' => array(
				$t( 'hero_eyebrow', 'Eyebrow', 'hero_eyebrow' ),
				$t( 'hero_title', 'Title (before gold)', 'hero_title' ),
				$t( 'hero_title_em', 'Gold words', 'hero_title_em' ),
				$ta( 'hero_subtitle', 'Subtitle', 'hero_subtitle' ),
			) ),
			array( 'key' => 'seo', 'label' => 'SEO', 'fields' => array(
				$t( 'meta_title', 'SEO title', 'meta_title' ),
				$ta( 'meta_description', 'SEO description', 'meta_description' ),
			) ),
		),
	),
	'home' => array(
		'title'        => 'Home',
		'frontendPath' => '/',
		// Orderable render blocks (drag panel). Keys match the frontend's
		// sectionBlocks + data-lp attributes. bento/assemble are code-only
		// showcase sections — orderable, no fields.
		'blocks'       => array(
			array( 'key' => 'clients', 'label' => 'Clients strip' ),
			array( 'key' => 'capabilities', 'label' => 'Capabilities' ),
			array( 'key' => 'stats', 'label' => 'Stats counters' ),
			array( 'key' => 'bento', 'label' => 'Bento showcase' ),
			array( 'key' => 'solutions', 'label' => 'Solutions' ),
			array( 'key' => 'process', 'label' => 'Process' ),
			array( 'key' => 'assemble', 'label' => 'Launch checklist scatter' ),
			array( 'key' => 'work', 'label' => 'Selected work' ),
			array( 'key' => 'kickoff', 'label' => 'First 14 days' ),
			array( 'key' => 'why', 'label' => 'Why Northline' ),
			array( 'key' => 'compare', 'label' => 'Comparison' ),
			array( 'key' => 'testimonials', 'label' => 'Testimonials' ),
			array( 'key' => 'pricing', 'label' => 'Pricing snapshot' ),
			array( 'key' => 'faq', 'label' => 'FAQ' ),
			array( 'key' => 'audit', 'label' => 'Free audit' ),
			array( 'key' => 'cta', 'label' => 'Final CTA' ),
		),
		'sections'     => array(
			array( 'key' => 'hero', 'label' => 'Hero', 'fields' => array(
				$t( 'hero_trusted_line', 'Trusted line', 'hero.trustedLine' ),
				$t( 'hero_headline', 'Headline', 'hero.headline' ),
				$ta( 'hero_subheadline', 'Subheadline', 'hero.subheadline' ),
				$t( 'hero_cta_label', 'CTA label', 'hero.ctaLabel' ),
				$t( 'hero_cta_href', 'CTA link', 'hero.ctaHref' ),
				$t( 'hero_cta_note', 'CTA note', 'hero.ctaNote' ),
				$rp( 'hero_cards', 'Marquee cards', 'hero.cards', array(
					$s( 'category', 'Category' ), $s( 'title', 'Title' ), $s( 'team', 'Team label' ),
					$s( 'img', 'Cover image', 'image' ),
					$s( 's1v', 'Stat 1 value' ), $s( 's1l', 'Stat 1 label' ),
					$s( 's2v', 'Stat 2 value' ), $s( 's2l', 'Stat 2 label' ),
					$s( 's3v', 'Stat 3 value' ), $s( 's3l', 'Stat 3 label' ) ) ),
			) ),
			array( 'key' => 'clients', 'label' => 'Clients strip', 'fields' => array(
				$t( 'clients_label', 'Label', 'clients.label' ),
				$ln( 'clients_names', 'Client names', 'clients.names' ),
			) ),
			array( 'key' => 'capabilities', 'label' => 'Capabilities', 'fields' => array(
				$t( 'capabilities_eyebrow', 'Eyebrow', 'capabilities.eyebrow' ),
				$t( 'capabilities_heading', 'Heading', 'capabilities.heading' ),
				$rp( 'capabilities_items', 'Cards', 'capabilities.items', array(
					$s( 'title', 'Title' ), $s( 'desc', 'Description', 'textarea' ) ) ),
			) ),
			array( 'key' => 'stats', 'label' => 'Stats counters', 'fields' => array(
				$rp( 'stats', 'Counters', 'stats', array(
					$s( 'value', 'Value' ), $s( 'label', 'Label' ) ) ),
			) ),
			array( 'key' => 'solutions', 'label' => 'Solutions', 'fields' => array(
				$t( 'solutions_eyebrow', 'Eyebrow', 'solutions.eyebrow' ),
				$t( 'solutions_heading', 'Heading', 'solutions.heading' ),
				$rp( 'solutions_items', 'Cards', 'solutions.items', array(
					$s( 'title', 'Title' ), $s( 'desc', 'Description', 'textarea' ) ) ),
			) ),
			array( 'key' => 'process', 'label' => 'Process', 'fields' => array(
				$t( 'process_eyebrow', 'Eyebrow', 'process.eyebrow' ),
				$t( 'process_heading', 'Heading', 'process.heading' ),
				$ta( 'process_intro', 'Intro', 'process.intro' ),
				$rp( 'process_items', 'Steps', 'process.items', array(
					$s( 'n', 'Number' ), $s( 't', 'Title' ), $s( 'd', 'Description', 'textarea' ) ) ),
			) ),
			// Cards themselves auto-mirror the Projects collection (add a
			// project in WP → it appears here and on /works). Only the
			// section heading is edited here.
			array( 'key' => 'work', 'label' => 'Selected work', 'fields' => array(
				$t( 'work_eyebrow', 'Eyebrow', 'work.eyebrow' ),
				$t( 'work_heading', 'Heading', 'work.heading' ),
			) ),
			array( 'key' => 'kickoff', 'label' => 'First 14 days', 'fields' => array(
				$t( 'kickoff_eyebrow', 'Eyebrow', 'kickoff.eyebrow' ),
				$t( 'kickoff_heading', 'Heading', 'kickoff.heading' ),
				$rp( 'kickoff_items', 'Timeline', 'kickoff.items', array(
					$s( 'day', 'Day' ), $s( 't', 'Title' ), $s( 'd', 'Description', 'textarea' ) ) ),
			) ),
			array( 'key' => 'why', 'label' => 'Why Northline', 'fields' => array(
				$t( 'why_eyebrow', 'Eyebrow', 'why.eyebrow' ),
				$t( 'why_heading', 'Heading', 'why.heading' ),
				$rp( 'why_items', 'Cards', 'why.items', array(
					$s( 'title', 'Title' ), $s( 'desc', 'Description', 'textarea' ) ) ),
			) ),
			array( 'key' => 'compare', 'label' => 'Comparison', 'fields' => array(
				$t( 'compare_eyebrow', 'Eyebrow', 'compare.eyebrow' ),
				$t( 'compare_heading', 'Heading', 'compare.heading' ),
				$t( 'compare_typical_title', 'Left column title', 'compare.typicalTitle' ),
				$ln( 'compare_typical', 'Left rows', 'compare.typical' ),
				$t( 'compare_northline_title', 'Right column title', 'compare.northlineTitle' ),
				$ln( 'compare_northline', 'Right rows', 'compare.northline' ),
			) ),
			array( 'key' => 'testimonials', 'label' => 'Testimonials heading', 'fields' => array(
				$t( 'testimonials_eyebrow', 'Eyebrow', 'testimonialsSection.eyebrow' ),
				$t( 'testimonials_heading', 'Heading', 'testimonialsSection.heading' ),
			) ),
			array( 'key' => 'pricing', 'label' => 'Pricing snapshot', 'fields' => array(
				$t( 'pricing_eyebrow', 'Eyebrow', 'pricing.eyebrow' ),
				$t( 'pricing_heading', 'Heading', 'pricing.heading' ),
				$rp( 'pricing_tiers', 'Tiers', 'pricing.tiers', array(
					$s( 't', 'Name' ), $s( 'd', 'Description', 'textarea' ), $s( 'p', 'Price' ), $s( 'featured', 'Featured (1/0)' ) ) ),
				$ln( 'pricing_tier_features', 'Shared features', 'pricing.tierFeatures' ),
			) ),
			array( 'key' => 'faq', 'label' => 'FAQ', 'fields' => array(
				$t( 'faq_eyebrow', 'Eyebrow', 'faq.eyebrow' ),
				$t( 'faq_heading', 'Heading', 'faq.heading' ),
				$rp( 'faq_items', 'Questions', 'faq.items', array(
					$s( 'q', 'Question' ), $s( 'a', 'Answer', 'textarea' ) ) ),
			) ),
			array( 'key' => 'audit', 'label' => 'Free audit', 'fields' => array(
				$t( 'audit_eyebrow', 'Eyebrow', 'audit.eyebrow' ),
				$t( 'audit_heading', 'Heading', 'audit.heading' ),
				$ta( 'audit_text', 'Text', 'audit.text' ),
				$ln( 'audit_bullets', 'Bullets', 'audit.bullets' ),
			) ),
			array( 'key' => 'cta', 'label' => 'Final CTA', 'fields' => array(
				$t( 'cta_eyebrow', 'Eyebrow', 'cta.eyebrow' ),
				$t( 'cta_heading', 'Heading', 'cta.heading' ),
				$ta( 'cta_text', 'Text', 'cta.text' ),
				$t( 'cta_primary_label', 'Primary button', 'cta.primaryLabel' ),
				$t( 'cta_secondary_label', 'Secondary button', 'cta.secondaryLabel' ),
			) ),
		),
	),
);

<?php
/**
 * LivePress schema — Realistic3D `faq` page.
 *
 * GENERATED FILE — do not edit by hand.
 * Source: lib/livepress/pages/faq.ts   Regenerate: npm run livepress:gen
 *
 * Dropped into `wp-content/plugins/livepress/`, where livepress-schema.php
 * merges every `schema-*.php` it finds. One file per editable page.
 *
 * kind: text | textarea | lines | image | repeater.
 *
 * Each field carries a `default`: the page's real copy, so a fresh
 * `sitepage` can be seeded from this file alone. `lines` defaults are
 * newline-separated and repeater defaults are JSON, exactly as the plugin's
 * save() writes them.
 *
 * `path` === `key` for every field, with no dot paths: the frontend overlays
 * live edits onto the RAW flat meta before adapting it, so an incoming
 * `{ path, value }` has to land on the meta key of the same name. The
 * generator derives `path` from `key` and gives no way to set it otherwise.
 */
defined( 'ABSPATH' ) || exit;

$t  = fn( $key, $label, $def = '' ) => array( 'key' => $key, 'label' => $label, 'kind' => 'text', 'path' => $key, 'default' => $def );
$ta = fn( $key, $label, $def = '' ) => array( 'key' => $key, 'label' => $label, 'kind' => 'textarea', 'path' => $key, 'default' => $def );
$ln = fn( $key, $label, $def = '' ) => array( 'key' => $key, 'label' => $label, 'kind' => 'lines', 'path' => $key, 'default' => $def );
$im = fn( $key, $label, $def = '' ) => array( 'key' => $key, 'label' => $label, 'kind' => 'image', 'path' => $key, 'default' => $def );
$rp = fn( $key, $label, $subs, $def = '' ) => array( 'key' => $key, 'label' => $label, 'kind' => 'repeater', 'path' => $key, 'subs' => $subs, 'default' => $def );
$s  = fn( $key, $label, $kind = 'text' ) => array( 'key' => $key, 'label' => $label, 'kind' => $kind );

return array(
	'faq' => array(
		'title'        => 'FAQ',
		'frontendPath' => '/faq',
		'sections'     => array(

			array( 'key' => 'masthead', 'label' => 'Masthead', 'fields' => array(
				$t( 'hero_kicker', 'Kicker', 'FAQ' ),
				$t( 'hero_kicker_muted', 'Kicker (muted half)', '/ ANSWERS' ),
				$t( 'hero_eyebrow', 'Eyebrow', 'Questions, answered' ),
				$ln( 'hero_title', 'Title (2 lines)', 'Everything worth
asking first' ),
				$ta( 'hero_body', 'Body', 'Scope, files, revisions, timelines and cost — the questions that come up before every project, answered in one place. Search below, or ask us anything we have missed.' ),
				$t( 'hero_count_label', 'Label after the question count', 'questions answered' ),
			) ),

			array( 'key' => 'questions', 'label' => 'Questions', 'fields' => array(
				$t( 'list_heading', 'List heading', 'All questions' ),
				$ta( 'list_intro', 'List intro', 'Search, or browse the full list below.' ),
				$rp( 'faqs', 'Questions', array(
					$s( 'q', 'Question' ),
					$s( 'a', 'Answer', 'textarea' ),
				), '[{"q":"What does Realistic3D do?","a":"Realistic3D is a UK-based 3D architectural visualisation studio. We create photorealistic interior and exterior renders, floor plan visuals, 3D animations, walkthroughs, flythroughs, Matterport 360 virtual tours, VR and AR visualisation, and product rendering for professional project presentations and marketing."},{"q":"Who do you usually work with?","a":"We work with architects, real estate developers, interior designers, construction companies, product-based businesses, eCommerce brands, and marketing teams that need realistic visuals to explain, approve, present, or sell a project."},{"q":"Where is Realistic3D based?","a":"Realistic3D is a UK studio based at 15 Bellevue Place, Southend On Sea SS1 2RA, with a second office at 100 Elizabeth St, Melbourne VIC 3004. We work with UK, Australian and international clients across property, design, construction, and product sectors."},{"q":"Why should I use 3D visualisation for my project?","a":"3D visualisation helps people understand a project before it is built. It can make designs clearer, support planning or stakeholder conversations, improve client presentations, help buyers imagine finished spaces, and create stronger marketing assets before construction is complete."},{"q":"Can you help if we are not sure what visual service we need?","a":"Yes. If you are unsure whether you need still renders, an animation, a walkthrough, a virtual tour, or a combination of outputs, send us your goal and project information. We can recommend the most practical visual format based on your audience, deadline, and use case."},{"q":"What makes Realistic3D different?","a":"Our work combines technical accuracy with artistic detail. We focus on proportion, materials, lighting, scale, and atmosphere, while following a structured workflow that keeps projects clear, reliable, and presentation-ready."},{"q":"What is architectural 3D rendering?","a":"Architectural 3D rendering is the process of turning drawings, plans, models, or design concepts into realistic digital images. These visuals show the final appearance of a building, room, site, or development before it is physically built."},{"q":"What is included in an interior 3D render?","a":"An interior render can include room layout, furniture, lighting, flooring, wall finishes, materials, decor, styling, fixtures, views, and atmosphere. The goal is to help clients understand how the finished space will look and feel."},{"q":"What is included in an exterior 3D render?","a":"An exterior render can show facade design, building materials, windows, lighting, landscaping, streetscape, surrounding context, sky, weather mood, and human activity. It is often used for planning, developer marketing, and project presentations."},{"q":"Do you create 3D floor plan visuals?","a":"Yes. 3D floor plans help viewers understand layout, room flow, furniture placement, scale, circulation, and how a space functions. They are useful for real estate marketing, sales brochures, and client presentations."},{"q":"Do you create 3D product renders?","a":"Yes. We create high-quality product visuals for brands, eCommerce, advertising, product launches, and marketing campaigns. Product CGI is useful when photography is difficult, expensive, or needed before the physical product is available."},{"q":"What makes a render look photorealistic?","a":"Photorealism depends on accurate modelling, realistic proportions, high-quality materials, natural lighting, believable shadows, thoughtful composition, correct camera settings, and attention to detail such as texture, reflections, landscaping, styling, and atmosphere."},{"q":"Can renders help with planning or client approvals?","a":"Yes. Renders can make technical plans easier to understand for clients, stakeholders, investors, buyers, and planning conversations. They help people see the final design intent more clearly than drawings alone."},{"q":"What is 3D architectural animation?","a":"3D architectural animation is a moving visual presentation of a project. It can show the viewer through a space, around a building, across a site, or through a product story using cinematic camera movement, lighting, materials, and atmosphere."},{"q":"What is the difference between a walkthrough and a flythrough?","a":"A walkthrough usually moves through a space at human scale, making it ideal for interiors, show homes, apartments, and user experience. A flythrough often moves around or above a project, making it useful for developments, campuses, commercial sites, and larger architectural schemes."},{"q":"When should we use animation instead of still renders?","a":"Use animation when the audience needs to understand movement, flow, sequence, scale, arrival, circulation, or the emotional experience of a space. Still renders are excellent for detail and composition, while animation is stronger for storytelling and spatial experience."},{"q":"How long does a 3D animation take to produce?","a":"Animation timelines depend on project scale, video length, camera path, detail level, scene complexity, and revision stages. A simple short walkthrough may be faster than a large commercial flythrough or multi-scene project animation."},{"q":"Can architectural animations be used for marketing?","a":"Yes. Animations can be used on websites, sales presentations, investor decks, social media, exhibition screens, property launch campaigns, and client meetings. They help people experience the project in a more complete and memorable way."},{"q":"What is a Matterport 360 virtual tour?","a":"A Matterport 360 virtual tour is an interactive experience that lets viewers explore a real or captured space remotely. It is useful for property marketing, inspections, sales, leasing, and giving people a clearer sense of a space without being physically present."},{"q":"What is 360° VR or AR visualisation?","a":"360° VR and AR visualisation creates immersive ways to experience a space or design. These outputs can help users explore rooms, developments, layouts, and environments before construction, renovation, or product launch."},{"q":"What is the difference between a virtual tour and an animation?","a":"An animation is usually a guided video experience with a set camera path. A virtual tour is more interactive, allowing the viewer to move through or explore the space at their own pace. The best choice depends on whether you want a cinematic story or an interactive experience."},{"q":"When should a property team use a virtual tour?","a":"A virtual tour is useful when buyers, tenants, clients, or stakeholders need to explore a space remotely. It is especially helpful for completed properties, sales suites, show homes, rental spaces, hospitality, commercial interiors, or immersive off-plan experiences."},{"q":"Can virtual tours support off-plan developments?","a":"Yes, depending on the project. Bespoke 360° or VR visualisation can help buyers and stakeholders explore unbuilt spaces. This can support off-plan sales, investor presentations, and marketing campaigns where a more immersive experience is needed."},{"q":"What files do you need to start?","a":"Useful files include architectural drawings, plans, elevations, CAD files, Revit files, SketchUp models, 3D models, material schedules, furniture selections, moodboards, reference images, site photos, and any notes about the purpose of the final visuals."},{"q":"Can you work from sketches or early concepts?","a":"Yes. We can start from sketches, early plans, moodboards, or reference images. If the design is still developing, we can help visualise the direction, but clearer inputs usually lead to faster and more accurate production."},{"q":"How does the production process work?","a":"The process usually starts with a brief and references, followed by 3D modelling, materials, lighting, camera composition, review, revisions, and final delivery. For animations or tours, the process may also include camera path planning, storyboard direction, and video output."},{"q":"Do you provide revisions?","a":"Yes. Review stages are part of a professional visualisation workflow. Revisions may cover modelling details, materials, styling, camera angle, lighting, landscaping, furniture, or final polish, depending on the agreed scope."},{"q":"What is the best way to give feedback?","a":"The best feedback is specific and visual. Marked-up screenshots, annotated PDFs, numbered comments, material references, or clear written notes help the team understand exactly what needs changing."},{"q":"What final files do you deliver?","a":"Final deliverables depend on the project. Still renders are commonly delivered as high-resolution image files for web, print, presentations, or marketing. Animations are usually delivered as video files, while tours or immersive experiences may be delivered through relevant platforms or web-ready formats."},{"q":"Can you match a specific visual style or mood?","a":"Yes. References are very helpful. You can share moodboards, lighting references, competitor examples, photography styles, material palettes, or brand direction so the visuals match the tone you want."},{"q":"How much does a 3D rendering project cost?","a":"Pricing depends on the project type, number of views, level of detail, available files, required realism, revision scope, deadline, and final output format. The best way to get an accurate quote is to send your brief, drawings, references, and required deliverables."},{"q":"What affects the cost of a render?","a":"Cost is influenced by scene complexity, modelling requirements, interior styling, landscaping, number of camera views, material detail, lighting complexity, revision rounds, and whether the project needs still images, animation, or immersive outputs."},{"q":"How long does a render take?","a":"Timeline depends on scope. A simple still image may be quicker, while large architectural scenes, multiple views, detailed interiors, animations, or virtual tours require more time. We confirm timing after reviewing the brief and available files."},{"q":"Can you handle urgent deadlines?","a":"We can review urgent projects and advise what is realistic. Fast turnaround depends on team availability, quality expectations, project complexity, and how complete the starting files are."},{"q":"How can I get an accurate quote?","a":"Send the project type, location, drawings or model files, number of required views, animation length if relevant, references, deadline, intended use, and any specific visual requirements. The more complete the brief, the more accurate the quote."},{"q":"Do you work with international clients?","a":"Yes. Realistic3D is UK-based and works with clients internationally. Remote collaboration is straightforward when project files, references, feedback, and delivery requirements are clearly shared."}]' ),
			) ),

			array( 'key' => 'cta', 'label' => 'Closing call to action', 'fields' => array(
				$t( 'cta_eyebrow', 'Eyebrow', '(still not answered?)' ),
				$ln( 'cta_title', 'Title (2 lines)', 'Ask us the one
that is not ' ),
				$t( 'cta_accent', 'Title (gold ending)', 'on this page.' ),
				$ta( 'cta_body', 'Body', 'Every project raises something these thirty-six do not cover. Send the drawings, the deadline, or just the question — we answer with a scope and a fixed quote, not a sales call.' ),
				$t( 'cta_primary', 'Primary button label', 'Ask a question' ),
				$t( 'cta_secondary', 'Secondary button label', 'See the work' ),
			) ),

			array( 'key' => 'seo', 'label' => 'Page metadata', 'fields' => array(
				$t( 'rank_math_focus_keyword', 'Focus keyword', '' ),
				$t( 'rank_math_title', 'Browser title', 'FAQ | Realistic3D 3D Rendering Questions & Answers' ),
				$ta( 'rank_math_description', 'Meta description', 'Read our FAQ to find answers about 3D rendering services, pricing, turnaround times, revisions, animations, virtual tours, VR, and AR projects' ),
			) ),
		),
	),
);

<?php
/**
 * Nexus by Therum — curated Google Product Taxonomy.
 *
 * Subset of Google's official 5,500+ entry taxonomy
 * (https://www.google.com/basepages/producttype/taxonomy-with-ids.en-US.txt)
 * — the ~200 most commonly used for WooCommerce stores. Covers apparel,
 * electronics, home & garden, beauty, food, sports, baby, business
 * supplies, media, toys, and arts & crafts.
 *
 * For obscure categories the UI offers a "paste custom" fallback that
 * accepts any valid Google taxonomy path or numeric ID. Don't try to
 * ship the full 5,500 — it'd bloat the plugin and bury the common cases.
 *
 * Maintenance: re-sync against Google's official list yearly. They add
 * a handful, almost never remove any.
 *
 * @return array<int, string> id => "Top > Sub > Leaf" path
 */

if ( ! defined( 'ABSPATH' ) ) exit;

function nexus_google_taxonomy(): array {
	return [
		// ─── Apparel & Accessories ───────────────────────────────────────
		166  => 'Apparel & Accessories',
		1604 => 'Apparel & Accessories > Clothing',
		212  => 'Apparel & Accessories > Clothing > Activewear',
		184  => 'Apparel & Accessories > Clothing > Dresses',
		5390 => 'Apparel & Accessories > Clothing > One-Pieces > Jumpsuits & Rompers',
		2271 => 'Apparel & Accessories > Clothing > Outerwear > Coats & Jackets',
		2306 => 'Apparel & Accessories > Clothing > Pants',
		211  => 'Apparel & Accessories > Clothing > Shirts & Tops',
		5322 => 'Apparel & Accessories > Clothing > Shorts',
		1581 => 'Apparel & Accessories > Clothing > Skirts',
		1594 => 'Apparel & Accessories > Clothing > Suits',
		2563 => 'Apparel & Accessories > Clothing > Sleepwear & Loungewear',
		2562 => 'Apparel & Accessories > Clothing > Swimwear',
		5181 => 'Apparel & Accessories > Clothing > Underwear & Socks',
		1933 => 'Apparel & Accessories > Shoes',
		187  => 'Apparel & Accessories > Handbags, Wallets & Cases',
		178  => 'Apparel & Accessories > Jewelry',
		193  => 'Apparel & Accessories > Jewelry > Bracelets',
		196  => 'Apparel & Accessories > Jewelry > Necklaces',
		200  => 'Apparel & Accessories > Jewelry > Rings',
		201  => 'Apparel & Accessories > Jewelry > Watches',
		167  => 'Apparel & Accessories > Clothing Accessories > Belts',
		1893 => 'Apparel & Accessories > Clothing Accessories > Hats',
		2020 => 'Apparel & Accessories > Clothing Accessories > Scarves & Shawls',
		1786 => 'Apparel & Accessories > Clothing Accessories > Sunglasses',
		1842 => 'Apparel & Accessories > Clothing Accessories > Ties',

		// ─── Electronics ─────────────────────────────────────────────────
		222  => 'Electronics',
		262  => 'Electronics > Audio',
		242  => 'Electronics > Audio > Audio Components > Headphones',
		241  => 'Electronics > Audio > Speakers',
		1270 => 'Electronics > Audio > Audio Players & Recorders',
		267  => 'Electronics > Cameras & Optics > Cameras',
		503739 => 'Electronics > Cameras & Optics > Camera Accessories',
		340  => 'Electronics > Communications > Telephony > Mobile Phones',
		325  => 'Electronics > Communications > Telephony > Mobile Phone Accessories',
		278  => 'Electronics > Computers',
		328  => 'Electronics > Computers > Laptops',
		325  => 'Electronics > Computers > Tablet Computers',
		298  => 'Electronics > Computers > Desktop Computers',
		386  => 'Electronics > Computer Accessories',
		328  => 'Electronics > Computers > Computer Components',
		386  => 'Electronics > Computers > Computer Accessories > Keyboards',
		2415 => 'Electronics > Computers > Computer Accessories > Mice & Trackballs',
		386  => 'Electronics > Computers > Computer Accessories > Webcams',
		404  => 'Electronics > Video > Televisions',
		412  => 'Electronics > Video > Video Game Consoles',
		1294 => 'Electronics > Video > Video Game Accessories',
		1305 => 'Electronics > Video > Video Players & Recorders',
		2082 => 'Electronics > Electronics Accessories > Cables',
		3422 => 'Electronics > Electronics Accessories > Power > Batteries',

		// ─── Home & Garden ───────────────────────────────────────────────
		536  => 'Home & Garden',
		699  => 'Home & Garden > Kitchen & Dining',
		730  => 'Home & Garden > Kitchen & Dining > Cookware & Bakeware',
		736  => 'Home & Garden > Kitchen & Dining > Kitchen Appliances',
		641  => 'Home & Garden > Kitchen & Dining > Tableware > Dinnerware',
		2920 => 'Home & Garden > Kitchen & Dining > Tableware > Drinkware',
		594  => 'Home & Garden > Decor',
		696  => 'Home & Garden > Decor > Artwork',
		574  => 'Home & Garden > Decor > Candles',
		598  => 'Home & Garden > Decor > Clocks',
		595  => 'Home & Garden > Decor > Decorative Tray',
		696  => 'Home & Garden > Decor > Mirrors',
		2334 => 'Home & Garden > Decor > Vases',
		436  => 'Home & Garden > Furniture',
		451  => 'Home & Garden > Furniture > Chairs',
		462  => 'Home & Garden > Furniture > Sofas',
		443  => 'Home & Garden > Furniture > Beds & Accessories',
		630  => 'Home & Garden > Linens & Bedding',
		505764 => 'Home & Garden > Linens & Bedding > Bedding > Comforters & Quilts',
		2746 => 'Home & Garden > Linens & Bedding > Bedding > Sheets',
		2700 => 'Home & Garden > Linens & Bedding > Bath Linens > Bath Towels',
		630  => 'Home & Garden > Lawn & Garden',
		689  => 'Home & Garden > Lawn & Garden > Outdoor Living > Outdoor Furniture',
		623  => 'Home & Garden > Household Supplies > Cleaning',
		2547 => 'Home & Garden > Smart Home > Smart Locks',

		// ─── Health & Beauty ────────────────────────────────────────────
		469  => 'Health & Beauty',
		2915 => 'Health & Beauty > Personal Care',
		2917 => 'Health & Beauty > Personal Care > Cosmetics',
		2526 => 'Health & Beauty > Personal Care > Cosmetics > Makeup',
		2917 => 'Health & Beauty > Personal Care > Cosmetics > Skin Care',
		2915 => 'Health & Beauty > Personal Care > Cosmetics > Bath & Body',
		2526 => 'Health & Beauty > Personal Care > Hair Care',
		2526 => 'Health & Beauty > Personal Care > Hair Care > Shampoo & Conditioner',
		2917 => 'Health & Beauty > Personal Care > Hair Care > Hair Coloring',
		484  => 'Health & Beauty > Personal Care > Cosmetics > Perfume & Cologne',
		2915 => 'Health & Beauty > Personal Care > Oral Care',
		485  => 'Health & Beauty > Health Care',
		505818 => 'Health & Beauty > Health Care > Fitness & Nutrition > Vitamins & Supplements',

		// ─── Food, Beverages & Tobacco ──────────────────────────────────
		412  => 'Food, Beverages & Tobacco > Beverages > Coffee',
		2073 => 'Food, Beverages & Tobacco > Beverages > Tea & Infusions',
		499676 => 'Food, Beverages & Tobacco > Beverages > Wine',
		499677 => 'Food, Beverages & Tobacco > Beverages > Beer',
		543553 => 'Food, Beverages & Tobacco > Beverages > Soft Drinks',
		428  => 'Food, Beverages & Tobacco > Food Items > Snacks',
		2660 => 'Food, Beverages & Tobacco > Food Items > Candy & Chocolate',
		427  => 'Food, Beverages & Tobacco > Food Items > Baking & Cooking',

		// ─── Sporting Goods ──────────────────────────────────────────────
		988  => 'Sporting Goods',
		499979 => 'Sporting Goods > Exercise & Fitness',
		1011 => 'Sporting Goods > Outdoor Recreation > Camping & Hiking',
		1029 => 'Sporting Goods > Outdoor Recreation > Cycling',
		1145 => 'Sporting Goods > Athletics',
		499718 => 'Sporting Goods > Athletics > Team Sports',
		989  => 'Sporting Goods > Athletics > Yoga & Pilates',

		// ─── Baby & Toddler ──────────────────────────────────────────────
		537  => 'Baby & Toddler',
		543574 => 'Baby & Toddler > Baby Bathing',
		540  => 'Baby & Toddler > Baby & Toddler Clothing',
		542 =>  'Baby & Toddler > Baby Toys & Activity Equipment',
		566  => 'Baby & Toddler > Diapering',
		8546 => 'Baby & Toddler > Baby Health',

		// ─── Toys & Games ────────────────────────────────────────────────
		1239 => 'Toys & Games > Toys',
		3206 => 'Toys & Games > Toys > Action Figures',
		1247 => 'Toys & Games > Toys > Building Toys',
		1253 => 'Toys & Games > Toys > Dolls, Playsets & Toy Figures',
		3793 => 'Toys & Games > Toys > Educational Toys',
		1242 => 'Toys & Games > Games',
		4407 => 'Toys & Games > Games > Card Games',
		4408 => 'Toys & Games > Games > Board Games',
		1239 => 'Toys & Games > Games > Puzzles',

		// ─── Media (Books, Music, Movies, Software) ─────────────────────
		783  => 'Media > Books',
		855  => 'Media > Music & Sound Recordings',
		839  => 'Media > Movies',
		313  => 'Media > Music & Sound Recordings > Music CDs',
		2065 => 'Software > Computer Software > Computer Operating Systems',

		// ─── Office Supplies ─────────────────────────────────────────────
		950  => 'Office Supplies',
		972  => 'Office Supplies > General Office Supplies',
		923  => 'Office Supplies > Office Equipment > Office Computers > Printers, Copiers & Fax Machines',

		// ─── Animals & Pet Supplies ──────────────────────────────────────
		1  =>  'Animals & Pet Supplies',
		2  =>  'Animals & Pet Supplies > Pet Supplies',
		3367 => 'Animals & Pet Supplies > Pet Supplies > Cat Supplies',
		3258 => 'Animals & Pet Supplies > Pet Supplies > Dog Supplies',

		// ─── Arts & Crafts ──────────────────────────────────────────────
		8  =>  'Arts & Entertainment > Hobbies & Creative Arts',
		505370 => 'Arts & Entertainment > Hobbies & Creative Arts > Arts & Crafts',
		505812 => 'Arts & Entertainment > Hobbies & Creative Arts > Arts & Crafts > Art & Crafting Materials',
	];
}

/**
 * Look up a taxonomy path by ID. Returns empty string if unknown.
 */
function nexus_google_taxonomy_label( $id_or_path ): string {
	$tx = nexus_google_taxonomy();
	if ( is_numeric( $id_or_path ) && isset( $tx[ (int) $id_or_path ] ) ) {
		return $tx[ (int) $id_or_path ];
	}
	return (string) $id_or_path; // already a path or custom
}

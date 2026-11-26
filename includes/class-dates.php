<?php
/**
 * The date of a bazna cijena: per product category, and the day a product was added (spec §1.3).
 *
 * The date is the LATER of two: the date of the product's category (10.9.2026. or 2.5.2025.) or the day the product
 * was put on sale in the shop (WooCommerce's date_created). A product's own date always wins. In two categories with
 * different dates the earlier one counts and the product is reported on Status. Without a category: "Other goods".
 *
 * The dates per category are a setting with a state (option cx_sidrena_dates): "suggested" from category names on a
 * fresh install (filling waits until someone saves them), "taken" from the shop's existing data after a move from
 * MJDD (they count as saved, and no date a customer sees changes), "saved" by a person. The dates and the two choices
 * are settings, not code: the regulation is a draft (plan §8).
 *
 * A date, once a product has a bazna, never changes by itself: filling stores it on the product, and saving other
 * Dates, a take-over, or a change of categories (a product's categories, a category moved under another or deleted)
 * first stores the current date on every product whose date would otherwise move.
 *
 * @package CX_Sidrena
 */

defined( 'ABSPATH' ) || exit;

/**
 * Dates of bazna cijena.
 */
final class CX_Sidrena_Dates {

	const OPTION = 'cx_sidrena_dates';

	/** The two dates of the draft regulation: everything else, and food and cosmetics. The first is the default. */
	const CHOICES = array( '10.09.2026.', '02.05.2025.' );

	/**
	 * Words in a category's own name that suggest the second date: the six groups of the draft (Kai, CX-SID-94): food,
	 * drink, cosmetics (whatever goes on the skin, hair or lips), cleaning products, toiletries and household products.
	 * Lower case, without diacritics, matched as the start of a word.
	 *
	 * Drink by its kind too (CX-SID-192): Pravilnik NN 132/2026-1583, čl. 3 st. 1: »za hranu, piće, kozmetiku, sredstva za
	 * čišćenje, toaletne potrepštine i proizvode za kućanstvo istaknuti baznu cijenu koja odgovara maloprodajnoj cijeni koju
	 * je za navedenu robu primijenio na dan 2. svibnja 2025.« A shop of drink names its categories Whisky, Rum, Pjenušac,
	 * Likeri, not "pića". A kind that starts other words (rum, gin, medica, porto) is in KEYWORDS_WHOLE instead.
	 */
	const KEYWORDS = array(
		'hrana', 'prehran', 'namirnic', 'mlijek', 'mlijecn', 'kruh', 'pekar', 'tjestenin', 'slatkis', 'cokolad', 'grickal',
		'zacin', 'konzerv', 'voce', 'povrc', 'meso', 'mesn', 'sok', 'pice', 'napit', 'kava', 'caj', 'vino', 'pivo', 'alkohol',
		'kozmet', 'njega', 'sampon', 'krem', 'sapun', 'parfem', 'dezodor', 'losion', 'balzam', 'sminka', 'toalet', 'pelen',
		'maramic', 'zubn', 'brijanj', 'higijen', 'deterdz', 'ciscen', 'dezinf', 'pranj', 'rublj', 'kucanstv', 'domacinstv',
		'food', 'grocer', 'snack', 'dairy', 'bakery', 'sweets', 'chocolate', 'spice', 'juice', 'drink', 'beverage', 'coffee',
		'wine', 'beer', 'cosmetic', 'skincare', 'shampoo', 'cream', 'soap', 'perfume', 'deodorant', 'lotion', 'makeup',
		'toilet', 'diaper', 'nappies', 'tissue', 'toothpaste', 'shaving', 'hygiene', 'detergent', 'cleaning', 'disinfect',
		'laundry', 'dishwash', 'household',
		'whisk', 'viski', 'vodk', 'votk', 'liker', 'liqueur', 'liquor', 'rakij', 'konjak', 'konjac', 'cognac', 'brandy',
		'pjenus', 'sampanj', 'champag', 'prosecc', 'tonic', 'tonik', 'tequil', 'tekil', 'mezcal', 'pelinkov', 'travaric',
		'sljivovic', 'absint', 'vermut', 'vermouth', 'aperitiv', 'cider', 'jabukovac', 'koktel', 'cocktail', 'zestok',
		'lozovac', 'orahovac', 'visnjevac', 'komovic', 'grapp', 'sherry',
	);

	/**
	 * The same groups, as whole words only: parts of the body (cosmetics for them), short words that start others. Kinds of
	 * drink with their Croatian forms (CX-SID-192): rum not in Rumunjska or Rumenilo, gin not in Ginkgo or Origin, destilat
	 * not in Destilatori, medica not in Medicinski, džin not in Džins; and the plural forms of piće, vino, pivo and kava,
	 * which the start words above do not reach (Žestoka pića, Bijela vina, Craft piva, Mljevene kave).
	 */
	const KEYWORDS_WHOLE = array(
		'lice', 'lica', 'tijelo', 'tijela', 'kosa', 'kose', 'kosu', 'usne', 'nokti', 'noktiju',
		'body', 'face', 'hair', 'lips', 'skin', 'nails', 'tea',
		'rum', 'ruma', 'rumu', 'rumom', 'rumovi', 'rumova', 'rumove', 'rumovima',
		'gin', 'ginu', 'ginom', 'ginovi', 'ginova', 'ginove', 'ginovima',
		'dzin', 'dzina', 'dzinu', 'dzinom', 'dzinovi', 'dzinova', 'dzinove', 'dzinovima',
		'pica', 'picu', 'picima', 'vina', 'vinu', 'vinima', 'piva', 'pivu', 'pivima', 'kave', 'kavu', 'kavi', 'kavom',
		'spirits', 'brendi', 'brendija', 'destilat', 'destilati', 'destilata', 'destilatu', 'destilatom', 'destilatima',
		'medica', 'medice', 'medici', 'medicu', 'medicom', 'biska', 'biske', 'biski', 'bisku', 'biskom',
		'prosek', 'proseka', 'proseku', 'prosekom', 'porto', 'sake', 'amaro', 'bitter', 'bitteri',
	);

	/**
	 * Words that say a category is not for people or is equipment (devices, parts, tools, vehicles, aquariums and
	 * ponds, animals and their food): 10.9.2026. even next to a word of KEYWORDS ("Auto kozmetika", "Hrana za pse",
	 * a pond's "Čišćenje i održavanje"). Start of a word, or whole words in NOT_FOR_PEOPLE_WHOLE.
	 */
	const NOT_FOR_PEOPLE = array(
		'uredaj', 'aparat', 'stroj', 'alat', 'oprem', 'dijelov', 'rezervn', 'pribor', 'auto', 'motor', 'vozil', 'bicikl',
		'akvarij', 'jezer', 'ribnjak', 'zivotinj', 'ljubimc', 'za pse', 'za macke', 'za ptice', 'za ribe',
		'equipment', 'device', 'appliance', 'spare', 'accessor', 'vehicle', 'aquarium', 'pond',
	);

	/**
	 * Whole words of the same kind. Glasses and stills (CX-SID-192, Rook 4475: "Čaše za whisky", "Kotlovi za rakiju") as
	 * whole words of čaša and kotao: as starts of words they would also take Kotleti, Cashmere, Cassava or a wine named
	 * Castello. Without "casa", which is a wine's "Casa" too.
	 */
	const NOT_FOR_PEOPLE_WHOLE = array(
		'pet', 'pets', 'dog', 'dogs', 'cat', 'cats', 'tool', 'tools', 'car', 'cars',
		'case', 'casi', 'casu', 'casom', 'casama', 'casica', 'casice', 'casicu', 'casicom', 'casicama',
		'kotao', 'kotla', 'kotlu', 'kotlom', 'kotlovi', 'kotlova', 'kotlove', 'kotlovima',
	);

	/**
	 * Words of a category that may hold goods of both dates (gifts, sets, sales, "other"): its date follows its parent,
	 * and the screen asks the owner to check it. Start of a word, or whole words in MIXED_WHOLE.
	 */
	const MIXED = array(
		'poklon', 'gift', 'paket', 'akcij', 'snizen', 'rasprodaj', 'razno', 'ostal', 'nekategoriz', 'uncategoriz', 'outlet',
		'bundle',
	);

	/** Whole words of the same kind. */
	const MIXED_WHOLE = array( 'set', 'sets', 'novo', 'sale', 'mix', 'misc', 'other', 'others' );

	/** WP-cron event that runs (and resumes) the take-over outside a customer's request. */
	const SETUP_HOOK = 'cx_sidrena_dates_setup';

	/** How far an unfinished take-over got: { last: product id, frozen: n, at: time }. */
	const PROGRESS_OPTION = 'cx_sidrena_dates_takeover';

	/** Seconds a take-over works in one WP-cron run, and in one admin page load. */
	const CRON_BUDGET  = 20;
	const ADMIN_BUDGET = 8;

	/** Product statuses whose dates count (as in the fill). */
	const STATUSES = array( 'publish', 'private', 'draft', 'pending', 'future' );

	/**
	 * Request cache: category parents and dates, product categories.
	 *
	 * @var array<string,mixed>
	 */
	private static $cache = array();

	/**
	 * Hook up.
	 */
	public static function register() {
		// After WooCommerce registered product_cat (init 5). Covers a silent activation and an update from 0.1.0.
		add_action( 'init', array( __CLASS__, 'maybe_setup' ), 99 );
		add_action( self::SETUP_HOOK, array( __CLASS__, 'run_cron' ) );
		add_action( 'created_product_cat', array( __CLASS__, 'flush_cache' ) );
		add_action( 'edited_product_cat', array( __CLASS__, 'flush_cache' ) );
		add_action( 'edited_term_taxonomies', array( __CLASS__, 'flush_cache' ) );
		// A change of categories never moves the date a product with a bazna shows (Argus B3).
		add_action( 'set_object_terms', array( __CLASS__, 'on_set_terms' ), 10, 6 );
		add_action( 'edit_terms', array( __CLASS__, 'before_term_edit' ), 10, 3 );
		add_action( 'edited_term', array( __CLASS__, 'after_term_edit' ), 10, 3 );
		add_action( 'pre_delete_term', array( __CLASS__, 'before_term_delete' ), 10, 2 );
		add_action( 'delete_term', array( __CLASS__, 'after_term_delete' ), 10, 3 ); // fires after the term is gone
	}

	/**
	 * Forget what this request cached.
	 */
	public static function flush_cache() {
		self::$cache = array();
	}

	// ----------------------------------------------------------------------------------------------------------------
	// The setting
	// ----------------------------------------------------------------------------------------------------------------

	/**
	 * The stored Dates, or null before they were first set up.
	 *
	 * @return array{map:array<int,string>,other:string,choices:array<int,string>,state:string,by:string,user:int,on:int,frozen:int}|null
	 */
	public static function get() {
		$o = get_option( self::OPTION, null );
		if ( ! is_array( $o ) ) {
			return null;
		}
		$map = array();
		foreach ( isset( $o['map'] ) && is_array( $o['map'] ) ? $o['map'] : array() as $term => $date ) {
			$map[ (int) $term ] = (string) $date;
		}
		$choices = isset( $o['choices'] ) && is_array( $o['choices'] ) && $o['choices'] ? array_values( array_map( 'strval', $o['choices'] ) ) : self::CHOICES;

		return array(
			'map'     => $map,
			'other'   => isset( $o['other'] ) ? (string) $o['other'] : $choices[0],
			'choices' => $choices,
			'state'   => isset( $o['state'] ) ? (string) $o['state'] : 'suggested',
			'by'      => isset( $o['by'] ) ? (string) $o['by'] : '',
			'user'    => isset( $o['user'] ) ? (int) $o['user'] : 0,
			'on'      => isset( $o['on'] ) ? (int) $o['on'] : 0,
			'frozen'  => isset( $o['frozen'] ) ? (int) $o['frozen'] : 0,
		);
	}

	/**
	 * The dates a category may have.
	 *
	 * @return array<int,string>
	 */
	public static function choices() {
		$d = self::get();

		return $d ? $d['choices'] : self::CHOICES;
	}

	/**
	 * State of the Dates for the screens.
	 *
	 * @return array{state:string,by:string,on:int}  state: none (not set up yet), suggested, taken or saved.
	 */
	public static function state() {
		$d = self::get();
		if ( ! $d ) {
			return array( 'state' => 'none', 'by' => '', 'on' => 0 );
		}

		return array( 'state' => $d['state'], 'by' => $d['by'], 'on' => $d['on'] );
	}

	/**
	 * Whether filling may write dates: someone saved them, or they were taken over from MJDD.
	 *
	 * @return bool
	 */
	public static function dates_saved() {
		$d = self::get();

		return $d && in_array( $d['state'], array( 'saved', 'taken' ), true );
	}

	/**
	 * First request with the plugin active and no Dates yet. Where products already show a bazna (a move from MJDD, or a
	 * shop that ran 0.1.0 with bazna entered by hand) the Dates are taken from what they show, so no date a customer
	 * sees moves with the update. Only where no product has a bazna yet are they suggested from category names. Never
	 * runs twice.
	 */
	public static function maybe_setup() {
		if ( null !== self::get() || ! taxonomy_exists( 'product_cat' ) ) {
			return;
		}
		if ( ! self::came_from_mjdd() && ! self::any_bazna() ) {
			list( $map, $other ) = self::suggest();
			self::store( $map, $other, self::CHOICES, 'suggested', '', 0, 0 );
			return;
		}
		// The take-over reads every product with a bazna. The first request after an automatic update is often a
		// customer's: that request only schedules it (Rook 1610, Argus B2). Until it has run, every product shows the
		// one reference date, as before the update, so no date a customer sees moves meanwhile.
		if ( wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			self::run_take_over( defined( 'WP_CLI' ) && WP_CLI ? 0 : self::CRON_BUDGET );
		} elseif ( is_admin() && function_exists( 'current_user_can' ) && CX_Sidrena_Plugin::current_user_can_manage() ) {
			self::run_take_over( self::ADMIN_BUDGET );
		} else {
			self::schedule_setup( 0 );
		}
	}

	/**
	 * @param int $delay Seconds.
	 */
	private static function schedule_setup( $delay ) {
		if ( ! wp_next_scheduled( self::SETUP_HOOK ) ) {
			wp_schedule_single_event( time() + (int) $delay, self::SETUP_HOOK );
		}
	}

	/**
	 * WP-cron: the take-over, or the next part of it.
	 */
	public static function run_cron() {
		if ( null === self::get() && taxonomy_exists( 'product_cat' ) ) {
			self::run_take_over( self::CRON_BUDGET );
		}
	}

	/**
	 * Run the take-over, one request at a time, for at most $budget seconds (0: to the end). An unfinished one is
	 * scheduled to go on from where it stopped.
	 *
	 * @param float $budget   Seconds.
	 * @param int   $max_rows At most this many products checked (0: no limit; tests).
	 * @return bool Whether the Dates are set up now.
	 */
	public static function run_take_over( $budget, $max_rows = 0 ) {
		if ( null !== self::get() ) {
			return true;
		}
		if ( ! self::lock() ) {
			return false; // another request is at it
		}
		try {
			$done = null !== self::take_over( $budget, $max_rows );
		} finally {
			self::unlock();
		}
		if ( ! $done ) {
			self::schedule_setup( 1 );
		}

		return $done;
	}

	/**
	 * Save the Dates (Settings): only dates from choices(). Products that already have a bazna keep the date they
	 * show now: where the new Dates would move it, it is stored on the product first.
	 *
	 * @param array<int,string> $map   Category id => date, only categories set directly (a missing one follows its
	 *                                 parent, then Other goods).
	 * @param string            $other Date of Other goods.
	 * @param int|null          $user  Who saved (default: current user).
	 * @return array{frozen:int}|WP_Error
	 */
	public static function save( array $map, $other, $user = null ) {
		$choices = self::choices();
		$clean   = array();
		foreach ( $map as $term => $date ) {
			$date = CX_Sidrena_History::parse_date( (string) $date );
			if ( ! in_array( $date, $choices, true ) || ! term_exists( (int) $term, 'product_cat' ) ) {
				return new WP_Error( 'bad_date', __( 'The date must be a real date, e.g. 10.9.2026.', 'cx-sidrena' ) );
			}
			$clean[ (int) $term ] = $date;
		}
		$other = CX_Sidrena_History::parse_date( (string) $other );
		if ( ! in_array( $other, $choices, true ) ) {
			return new WP_Error( 'bad_date', __( 'The date must be a real date, e.g. 10.9.2026.', 'cx-sidrena' ) );
		}

		$frozen = self::freeze( $clean, $other, array( 'type' => 'dates' ) )['n'];

		$user = null === $user ? get_current_user_id() : (int) $user;
		$u    = $user ? get_userdata( $user ) : false;
		self::store( $clean, $other, $choices, 'saved', $u ? (string) $u->display_name : '', $user, time() );

		return array( 'frozen' => $frozen );
	}

	/**
	 * @param array<int,string> $map     Map.
	 * @param string            $other   Other goods.
	 * @param array<int,string> $choices Choices.
	 * @param string            $state   suggested|taken|saved.
	 * @param string            $by      Name.
	 * @param int               $user    User id.
	 * @param int               $on      Time.
	 * @param int               $frozen  Products given an own date by a take-over.
	 */
	private static function store( array $map, $other, array $choices, $state, $by, $user, $on, $frozen = 0 ) {
		ksort( $map );
		update_option(
			self::OPTION,
			array(
				'v'       => 1,
				'map'     => $map,
				'other'   => $other,
				'choices' => array_values( $choices ),
				'state'   => $state,
				'by'      => $by,
				'user'    => $user,
				'on'      => $on,
				'frozen'  => $frozen,
			),
			true
		);
		self::flush_cache();
	}

	/**
	 * Dates suggested from category names (CX-SID-94), parents before children:
	 * - a word of NOT_FOR_PEOPLE in its own name: the first choice (10.9.2026.); checked when the name also has a word
	 *   of KEYWORDS ("Auto kozmetika");
	 * - a word of KEYWORDS in its own name: the second choice (2.5.2025.), but the first and checked when a parent's name
	 *   has a word of NOT_FOR_PEOPLE (a pond's "Čišćenje i održavanje"); checked when the name also has a word of MIXED;
	 * - only a word of MIXED: its parent's date, checked;
	 * - none: its parent's date (at the top the first choice).
	 * Only where a category would differ from its parent is it set directly. "Checked" means the screen asks the owner
	 * to look at it while the Dates are only suggested.
	 *
	 * @return array{0:array<int,string>,1:string,2:array<int,bool>} The map, Other goods, the categories to check.
	 */
	public static function suggest() {
		$map   = array();
		$check = array();
		$nfp   = array(); // categories whose own name or a parent's says: not for people
		foreach ( self::categories() as $term ) {
			$id     = (int) $term->term_id;
			$parent = (int) $term->parent;
			$has    = $parent ? self::date_of_category( $parent, $map, self::CHOICES[0] ) : self::CHOICES[0];
			$fmcg   = self::has_word( $term->name, self::KEYWORDS, self::KEYWORDS_WHOLE );
			$own    = self::has_word( $term->name, self::NOT_FOR_PEOPLE, self::NOT_FOR_PEOPLE_WHOLE );
			$mixed  = self::has_word( $term->name, self::MIXED, self::MIXED_WHOLE );
			$above  = $parent && isset( $nfp[ $parent ] );
			if ( $own || $above ) {
				$nfp[ $id ] = true;
			}
			if ( $own ) {
				$want = self::CHOICES[0];
				if ( $fmcg ) {
					$check[ $id ] = true;
				}
			} elseif ( $fmcg && $above ) {
				$want         = self::CHOICES[0];
				$check[ $id ] = true;
			} elseif ( $fmcg ) {
				$want = self::CHOICES[1];
				if ( $mixed ) {
					$check[ $id ] = true;
				}
			} else {
				$want = $has; // follows its parent
				if ( $mixed ) {
					$check[ $id ] = true;
				}
			}
			if ( ! $parent || $want !== $has ) {
				$map[ $id ] = $want;
			}
		}

		return array( $map, self::CHOICES[0], $check );
	}

	/**
	 * Whether a category name has a word of a list: lower case, without diacritics, a word of $starts at the start of a
	 * word, a word of $whole as a whole word.
	 *
	 * @param string             $name   Category name.
	 * @param array<int,string>  $starts Words matched as the start of a word.
	 * @param array<int,string>  $whole  Words matched as whole words.
	 * @return bool
	 */
	private static function has_word( $name, array $starts, array $whole ) {
		$name = strtolower( remove_accents( wp_specialchars_decode( (string) $name, ENT_QUOTES ) ) );
		foreach ( $starts as $word ) {
			if ( preg_match( '/(^|[^a-z])' . preg_quote( $word, '/' ) . '/', $name ) ) {
				return true;
			}
		}
		foreach ( $whole as $word ) {
			if ( preg_match( '/(^|[^a-z])' . preg_quote( $word, '/' ) . '($|[^a-z])/', $name ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Dates from the shop's own data, so no date a customer sees changes (spec §1.2): after a move from MJDD, and on the
	 * update of a shop whose products already show a bazna under the one reference date of MJDD or 0.1.0.
	 *
	 * Each category gets the date most of its products show (own date, or that single reference date), from the
	 * choices; Other goods gets the reference date. A product with a bazna and no own date whose date under the rule
	 * would then differ from what it showed before (another majority in its category, or added after that date)
	 * gets that date stored as its own (recorded in the history as the take-over). State "taken".
	 *
	 * Runs in parts when it has to (a big shop): a product checked is never checked again, and the Dates are stored
	 * only at the end, so until then nothing a customer sees has moved.
	 *
	 * @param float $budget   Seconds (0: to the end).
	 * @param int   $max_rows At most this many products checked (0: no limit).
	 * @return int|null Products given an own date, or null when it stopped before the end.
	 */
	public static function take_over( $budget = 0, $max_rows = 0 ) {
		$deadline = $budget > 0 ? microtime( true ) + $budget : 0;
		$legacy  = self::legacy_date();
		$choices = self::CHOICES;
		if ( '' !== $legacy && ! in_array( $legacy, $choices, true ) ) {
			$choices[] = $legacy; // MJDD's reference date is a valid choice on this shop
		}

		// Votes per category: the date each product with a bazna shows today. A product the take-over already gave its
		// date votes the same as before it got it (that date is the one it showed).
		$votes    = array();
		$products = self::bazna_products();
		self::prime_terms( array_column( $products, 'cat_post' ) );
		foreach ( $products as $p ) {
			$shown = '' !== $p['own'] ? $p['own'] : $legacy;
			if ( ! in_array( $shown, $choices, true ) ) {
				continue;
			}
			foreach ( self::product_categories( $p['cat_post'] ) as $term ) {
				$votes[ $term ][ $shown ] = ( isset( $votes[ $term ][ $shown ] ) ? $votes[ $term ][ $shown ] : 0 ) + 1;
			}
		}
		list( $suggested, $unused ) = self::suggest();
		unset( $unused );
		$other = '' !== $legacy ? $legacy : self::CHOICES[0];
		$map   = array();
		foreach ( self::categories() as $term ) { // parents first, so a child set like its parent stays unset
			$id = (int) $term->term_id;
			if ( ! empty( $votes[ $id ] ) ) {
				arsort( $votes[ $id ] );
				$want = (string) key( $votes[ $id ] );
			} else {
				$want = self::date_of_category( $id, $suggested, $other );
			}
			$parent = (int) $term->parent;
			if ( ! $parent || self::date_of_category( $parent, $map, $other ) !== $want ) {
				$map[ $id ] = $want;
			}
		}

		// Products whose date would move get the date they show now stored on them FIRST, and only then are the Dates
		// stored (Argus B2: the other way round, a request cut half-way left "taken" with dates already moved, and
		// nothing ran again). Cut half-way now, the option is still missing: the shop shows the one reference date as
		// before, and the next request runs the take-over again; products already given their date keep it, and they
		// vote the same, so it ends where an uncut run would have.
		$prog = get_option( self::PROGRESS_OPTION, array() );
		$prog = is_array( $prog ) ? $prog : array();
		if ( ! $prog ) {
			// Products with a bazna and no own date when it starts: at the end, the ones that got one are the frozen
			// count, also those a cut request wrote before it could save its progress (Argus, round 2: 4 instead of 6).
			$prog = array(
				'last'   => 0,
				'frozen' => 0,
				'base'   => count( self::no_own( $products ) ),
				'at'     => time(),
			);
			update_option( self::PROGRESS_OPTION, $prog, false );
		}
		$after = isset( $prog['last'] ) ? (int) $prog['last'] : 0;
		$sofar = isset( $prog['frozen'] ) ? (int) $prog['frozen'] : 0;
		$base  = isset( $prog['base'] ) ? (int) $prog['base'] : -1;
		$r     = self::freeze(
			$map,
			$other,
			array( 'type' => 'take_over' ),
			$choices,
			array(
				'after'    => $after,
				'deadline' => $deadline,
				'max'      => (int) $max_rows,
				'progress' => static function ( $last, $n ) use ( $sofar, $base ) {
					update_option( self::PROGRESS_OPTION, array( 'last' => (int) $last, 'frozen' => $sofar + (int) $n, 'base' => $base, 'at' => time() ), false );
				},
			)
		);
		$sofar += $r['n'];
		if ( ! $r['done'] ) {
			return null;
		}
		if ( $base >= 0 ) {
			$sofar = max( $sofar, $base - count( self::no_own( self::bazna_products() ) ) );
		}
		self::store( $map, $other, $choices, 'taken', '', 0, time(), $sofar );
		delete_option( self::PROGRESS_OPTION );

		return $sofar;
	}

	/**
	 * Categories (and types) of these products, read in a few queries.
	 *
	 * @param array<int,int> $ids Products.
	 */
	private static function prime_terms( array $ids ) {
		foreach ( array_chunk( array_values( array_unique( array_map( 'intval', $ids ) ) ), 500 ) as $chunk ) {
			update_object_term_cache( $chunk, 'product' );
		}
	}

	/**
	 * Before new Dates are saved: every product with a bazna and no own date whose date would move gets the date it
	 * shows now stored as its own, one by one, so a cut request keeps what it did.
	 *
	 * Reads no product meta: these products have no own date, so their date is the rule's (rule_for), which reads only
	 * the post, its parent and categories, loaded here in chunks.
	 *
	 * @param array<int,string>      $map     New map.
	 * @param string                 $other   New Other goods.
	 * @param array<string,mixed>    $context History context.
	 * @param array<int,string>|null $choices New choices (a take-over), or null to keep them.
	 * @param array<string,mixed>    $part    after (skip products up to this id), deadline (microtime, 0: none), max
	 *                                        (products checked, 0: no limit), progress (callback after each chunk).
	 * @return array{n:int,done:bool,last:int}
	 */
	private static function freeze( array $map, $other, array $context, $choices = null, array $part = array() ) {
		$after    = isset( $part['after'] ) ? (int) $part['after'] : 0;
		$deadline = isset( $part['deadline'] ) ? (float) $part['deadline'] : 0;
		$max      = isset( $part['max'] ) ? (int) $part['max'] : 0;
		$progress = isset( $part['progress'] ) && is_callable( $part['progress'] ) ? $part['progress'] : null;

		$todo = array();
		foreach ( self::bazna_products() as $p ) {
			if ( '' === $p['own'] && $p['id'] > $after ) {
				$todo[] = $p;
			}
		}
		$keep  = get_option( self::OPTION, array() );
		$trial = is_array( $keep ) ? $keep : array();
		$trial['map']   = $map;
		$trial['other'] = $other;
		if ( null !== $choices ) {
			$trial['choices'] = array_values( $choices );
		}
		$swap = static function () use ( $trial ) {
			return $trial;
		};

		$n       = 0;
		$checked = 0;
		$last    = $after;
		foreach ( array_chunk( $todo, 200 ) as $chunk ) {
			$ids = array();
			foreach ( $chunk as $p ) {
				$ids[] = $p['id'];
				$ids[] = $p['cat_post'];
			}
			$ids = array_values( array_unique( $ids ) );
			_prime_post_caches( $ids, false, false );
			self::prime_terms( $ids );
			foreach ( $chunk as $p ) {
				if ( ( $max > 0 && $checked >= $max ) || ( $deadline > 0 && $checked > 0 && microtime( true ) > $deadline ) ) {
					if ( $progress ) {
						call_user_func( $progress, $last, $n );
					}
					return array( 'n' => $n, 'done' => false, 'last' => $last );
				}
				$id   = (int) $p['id'];
				$now  = self::rule_for( $id )['date'];
				// The new rule, read without saving it: the option is swapped for this one read.
				add_filter( 'pre_option_' . self::OPTION, $swap );
				try {
					$then = self::rule_for( $id )['date'];
				} finally {
					remove_filter( 'pre_option_' . self::OPTION, $swap );
				}
				if ( $then !== $now ) {
					self::store_own( array( $id ), $now, $context, false );
					++$n;
				}
				++$checked;
				$last = $id;
			}
			if ( $progress ) {
				call_user_func( $progress, $last, $n );
			}
		}

		return array( 'n' => $n, 'done' => true, 'last' => $last );
	}

	/**
	 * @param array<int,int>      $ids     Products or variations.
	 * @param string              $date    Date.
	 * @param array<string,mixed> $context History context.
	 * @param bool                $flush   Forget this request's cache after (not while a trial rule is read).
	 */
	private static function store_own( array $ids, $date, array $context, $flush = true ) {
		if ( ! $ids ) {
			return;
		}
		// update_post_meta, not the product meta class: at activation (the take-over) that class is not loaded yet.
		CX_Sidrena_History::with(
			$context,
			static function () use ( $ids, $date ) {
				foreach ( $ids as $id ) {
					update_post_meta( (int) $id, CX_SIDRENA_META_DATE, $date );
				}
			}
		);
		if ( $flush ) {
			self::flush_cache();
		}
	}

	/**
	 * MJDD's (and before 0.1.1 our) single reference date, as stored.
	 *
	 * @return string
	 */
	private static function legacy_date() {
		$raw = class_exists( 'CX_Sidrena_Settings' ) ? (string) CX_Sidrena_Settings::instance()->get( 'default_date' ) : '';
		$d   = CX_Sidrena_History::parse_date( $raw );

		return '' !== $d ? $d : self::CHOICES[0];
	}

	// ----------------------------------------------------------------------------------------------------------------
	// A change of categories (Argus B3)
	// ----------------------------------------------------------------------------------------------------------------

	/**
	 * Dates shown before a change of a category, by the term being changed (edit_terms → edited_term,
	 * pre_delete_term → deleted_term).
	 *
	 * @var array<string,array<int,string>>
	 */
	private static $pending = array();

	/**
	 * Dates stored as a product's own in this request because a change of categories would have moved them, by id.
	 *
	 * @var array<int,string>
	 */
	private static $kept = array();

	/**
	 * The date stored as this product's own in this request by a change of categories, or ''. The product screen
	 * reads it: there WordPress sets the categories before WooCommerce saves the fields, and the date field the form
	 * showed was empty (Argus B3, round 2).
	 *
	 * @param int $id Product or variation.
	 * @return string
	 */
	public static function kept( $id ) {
		return isset( self::$kept[ (int) $id ] ) ? self::$kept[ (int) $id ] : '';
	}

	/**
	 * Products and variations with a bazna and no own date among these products (a variable product's variations
	 * too: they take its categories). Only their date can move with a category.
	 *
	 * @param array<int,int> $products Product ids.
	 * @return array<int,int>
	 */
	private static function dated_by_rule( array $products ) {
		global $wpdb;
		$products = array_values( array_filter( array_map( 'intval', $products ) ) );
		if ( ! $products ) {
			return array();
		}
		$in  = "'" . implode( "','", array_map( 'esc_sql', array_merge( self::STATUSES, array( 'inherit' ) ) ) ) . "'";
		$ids = implode( ',', $products );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				JOIN {$wpdb->postmeta} pr ON pr.post_id = p.ID AND pr.meta_key = %s AND pr.meta_value <> ''
				LEFT JOIN {$wpdb->postmeta} dt ON dt.post_id = p.ID AND dt.meta_key = %s
				WHERE ( ( p.post_type = 'product' AND p.ID IN ({$ids}) ) OR ( p.post_type = 'product_variation' AND p.post_parent IN ({$ids}) ) )
				AND p.post_status IN ({$in}) AND ( dt.meta_value IS NULL OR TRIM( dt.meta_value ) = '' ) ORDER BY p.ID",
				CX_SIDRENA_META_PRICE,
				CX_SIDRENA_META_DATE
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array_map( 'intval', (array) $out );
	}

	/**
	 * After the shown dates were read and something changed: each one that moved is stored as the product's own
	 * date, with a history row (source "dates" and the reason; the source of the bazna stays).
	 *
	 * @param array<int,string> $before Product id => date it showed.
	 * @param string            $reason History note.
	 * @return int
	 */
	private static function keep_shown( array $before, $reason ) {
		$n = 0;
		self::flush_cache();
		foreach ( $before as $id => $date ) {
			if ( '' !== trim( (string) get_post_meta( (int) $id, CX_SIDRENA_META_DATE, true ) ) ) {
				continue; // given its own date meanwhile
			}
			if ( self::date_for( (int) $id )['date'] !== $date ) {
				self::store_own( array( (int) $id ), (string) $date, array( 'type' => 'dates', 'reason' => $reason ) );
				self::$kept[ (int) $id ] = (string) $date;
				++$n;
			}
		}

		return $n;
	}

	/**
	 * A product's categories were set (product screen, quick and bulk edit, REST, WooCommerce's CSV import, a deleted
	 * category): a product with a bazna and no own date keeps the date it showed with the old categories.
	 * A product getting its first categories (being created) has nothing to keep.
	 *
	 * @param int    $object_id  Object.
	 * @param array  $terms      Terms given.
	 * @param array  $tt_ids     New term taxonomy ids.
	 * @param string $taxonomy   Taxonomy.
	 * @param bool   $append     Appended.
	 * @param array  $old_tt_ids Old term taxonomy ids.
	 */
	public static function on_set_terms( $object_id, $terms, $tt_ids, $taxonomy, $append = false, $old_tt_ids = array() ) {
		unset( $terms, $append );
		if ( 'product_cat' !== $taxonomy ) {
			return;
		}
		$object_id = (int) $object_id;
		self::flush_cache();
		$old = array_map( 'intval', (array) $old_tt_ids );
		$new = array_map( 'intval', (array) $tt_ids );
		sort( $old );
		sort( $new );
		if ( ! $old || $old === $new || null === self::get() || 'product' !== get_post_type( $object_id ) ) {
			return;
		}
		$items = self::dated_by_rule( array( $object_id ) );
		if ( ! $items ) {
			return;
		}
		global $wpdb;
		$old_terms = $wpdb->get_col( 'SELECT term_id FROM ' . $wpdb->term_taxonomy . ' WHERE term_taxonomy_id IN (' . implode( ',', $old ) . ')' ); // phpcs:ignore WordPress.DB
		$default   = (int) get_option( 'default_product_cat', 0 );
		// The date with the old categories: the same rule, with the product's category list as it was.
		self::$cache[ 'pc' . $object_id ] = array_values( array_diff( array_map( 'intval', (array) $old_terms ), array( $default ) ) );
		$before                           = array();
		foreach ( $items as $id ) {
			$before[ $id ] = self::date_for( $id )['date'];
		}
		self::keep_shown( $before, 'category changed' );
	}

	/**
	 * The shown date of every product with a bazna and no own date in this category or below it.
	 *
	 * @param int $term Category.
	 * @return array<int,string>
	 */
	private static function shown_below( $term ) {
		global $wpdb;
		$terms = array_merge( array( (int) $term ), array_map( 'intval', (array) get_term_children( (int) $term, 'product_cat' ) ) );
		$tts   = $wpdb->get_col( "SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE taxonomy = 'product_cat' AND term_id IN (" . implode( ',', $terms ) . ')' ); // phpcs:ignore WordPress.DB
		if ( ! $tts ) {
			return array();
		}
		$products = $wpdb->get_col( "SELECT DISTINCT object_id FROM {$wpdb->term_relationships} WHERE term_taxonomy_id IN (" . implode( ',', array_map( 'intval', $tts ) ) . ')' ); // phpcs:ignore WordPress.DB
		$before   = array();
		self::flush_cache();
		foreach ( self::dated_by_rule( (array) $products ) as $id ) {
			$before[ $id ] = self::date_for( $id )['date'];
		}

		return $before;
	}

	/**
	 * A category is about to be edited: when its parent changes, its products' dates may move (they follow another
	 * ancestor). Read them first. (Its own products' category list does not change, so on_set_terms does not run.)
	 *
	 * @param int    $term_id  Category.
	 * @param string $taxonomy Taxonomy.
	 * @param array  $args     New values (WordPress 6.1+).
	 */
	public static function before_term_edit( $term_id, $taxonomy, $args = array() ) {
		if ( 'product_cat' !== $taxonomy || null === self::get() ) {
			return;
		}
		$term = get_term( (int) $term_id, 'product_cat' );
		if ( ! $term instanceof WP_Term || ( is_array( $args ) && isset( $args['parent'] ) && (int) $args['parent'] === (int) $term->parent ) ) {
			return; // name or slug only
		}
		self::$pending[ 'edit' . (int) $term_id ] = self::shown_below( (int) $term_id );
	}

	/**
	 * @param int    $term_id  Category.
	 * @param int    $tt_id    Term taxonomy id.
	 * @param string $taxonomy Taxonomy.
	 */
	public static function after_term_edit( $term_id, $tt_id, $taxonomy ) {
		unset( $tt_id );
		if ( 'product_cat' !== $taxonomy ) {
			return;
		}
		$key = 'edit' . (int) $term_id;
		if ( isset( self::$pending[ $key ] ) ) {
			$before = self::$pending[ $key ];
			unset( self::$pending[ $key ] );
			self::keep_shown( $before, 'category moved' );
		}
	}

	/**
	 * A category is about to be deleted (pre_delete_term → delete_term): its products leave it (handled one by one in
	 * on_set_terms, which wp_delete_term calls) and its subcategories move up to its parent. Read the dates first.
	 *
	 * @param int    $term_id  Category.
	 * @param string $taxonomy Taxonomy.
	 */
	public static function before_term_delete( $term_id, $taxonomy ) {
		if ( 'product_cat' === $taxonomy && null !== self::get() ) {
			self::$pending[ 'del' . (int) $term_id ] = self::shown_below( (int) $term_id );
		}
	}

	/**
	 * @param int    $term_id  Category.
	 * @param int    $tt_id    Term taxonomy id.
	 * @param string $taxonomy Taxonomy.
	 */
	public static function after_term_delete( $term_id, $tt_id, $taxonomy ) {
		unset( $tt_id );
		if ( 'product_cat' !== $taxonomy ) {
			return;
		}
		$key = 'del' . (int) $term_id;
		if ( isset( self::$pending[ $key ] ) ) {
			$before = self::$pending[ $key ];
			unset( self::$pending[ $key ] );
			self::keep_shown( $before, 'category deleted' );
		}
	}

	/**
	 * The take-over's lock: a row in the options table added with INSERT IGNORE (one request wins, on MySQL and
	 * SQLite), taken over when older than 5 minutes (a request that died).
	 *
	 * @return bool
	 */
	private static function lock() {
		global $wpdb;
		$name     = self::OPTION . '_setup.lock';
		$suppress = $wpdb->suppress_errors( true );
		$ins      = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} ( option_name, option_value, autoload ) VALUES ( %s, %s, 'no' )", $name, (string) time() ) ); // phpcs:ignore WordPress.DB
		$wpdb->suppress_errors( $suppress );
		if ( $ins ) {
			return true;
		}
		$at = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) ); // phpcs:ignore WordPress.DB
		if ( null === $at || (int) $at > time() - 300 ) {
			return false;
		}

		return 1 === (int) $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", (string) time(), $name, (string) $at ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Release the take-over's lock.
	 */
	private static function unlock() {
		global $wpdb;
		$wpdb->delete( $wpdb->options, array( 'option_name' => self::OPTION . '_setup.lock' ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Whether any product or variation has a bazna cijena.
	 *
	 * @return bool
	 */
	private static function any_bazna() {
		global $wpdb;
		$in = "'" . implode( "','", array_map( 'esc_sql', array_merge( self::STATUSES, array( 'inherit' ) ) ) ) . "'";

		return (bool) $wpdb->get_var( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT 1 FROM {$wpdb->postmeta} pr JOIN {$wpdb->posts} p ON p.ID = pr.post_id
				WHERE pr.meta_key = %s AND pr.meta_value <> '' AND p.post_type IN ('product','product_variation')
				AND p.post_status IN ({$in}) LIMIT 1",
				CX_SIDRENA_META_PRICE
			)
		);
	}

	/**
	 * The shop came over from MJDD. Also a marker written by 0.1.0, which has no mjdd_at: an automatic update to this
	 * version runs no activation and no take-over, so the marker of a shop 0.1.0 took over stays as 0.1.0 wrote it.
	 *
	 * @return bool
	 */
	private static function came_from_mjdd() {
		return CX_Sidrena_History::mjdd_since() > 0;
	}

	// ----------------------------------------------------------------------------------------------------------------
	// Categories
	// ----------------------------------------------------------------------------------------------------------------

	/**
	 * Product categories, parents before children, alphabetical among siblings. The default category
	 * ("Uncategorized") is not one: its products are Other goods.
	 *
	 * @param bool $with_products Only categories that have products (counted with their subcategories' products).
	 * @return array<int,WP_Term> Depth of each in self::$cache['depth'][ term_id ].
	 */
	private static function categories( $with_products = false ) {
		$key = 'cats' . (int) $with_products;
		if ( isset( self::$cache[ $key ] ) ) {
			return self::$cache[ $key ];
		}
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'orderby'    => 'name',
				'order'      => 'ASC',
			)
		);
		$terms   = is_array( $terms ) ? $terms : array();
		$default = (int) get_option( 'default_product_cat', 0 );
		$by      = array();
		foreach ( $terms as $t ) {
			if ( (int) $t->term_id !== $default ) {
				$by[ (int) $t->parent ][] = $t;
			}
		}
		$out  = array();
		$depths = array();
		$walk   = static function ( $parent, $depth ) use ( &$walk, &$out, &$depths, $by ) {
			foreach ( isset( $by[ $parent ] ) ? $by[ $parent ] : array() as $t ) {
				$depths[ (int) $t->term_id ] = $depth;
				$out[]                       = $t;
				$walk( (int) $t->term_id, $depth + 1 );
			}
		};
		$walk( 0, 0 );
		self::$cache['depth'] = $depths;
		if ( $with_products ) {
			$counts = self::category_counts();
			$out    = array_values(
				array_filter(
					$out,
					static function ( $t ) use ( $counts ) {
						return ! empty( $counts[ (int) $t->term_id ] );
					}
				)
			);
		}
		self::$cache[ $key ] = $out;

		return $out;
	}

	/**
	 * Products (not variations) per category, a category counting its subcategories' products too.
	 *
	 * @return array<int,int>
	 */
	private static function category_counts() {
		if ( isset( self::$cache['counts'] ) ) {
			return self::$cache['counts'];
		}
		global $wpdb;
		$in   = "'" . implode( "','", array_map( 'esc_sql', self::STATUSES ) ) . "'";
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB
			"SELECT tt.term_id, tr.object_id FROM {$wpdb->term_relationships} tr
			JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'product_cat'
			JOIN {$wpdb->posts} p ON p.ID = tr.object_id AND p.post_type = 'product' AND p.post_status IN ({$in})"
		);
		$sets = array();
		foreach ( (array) $rows as $r ) {
			$term = (int) $r->term_id;
			foreach ( array_merge( array( $term ), self::ancestors( $term ) ) as $t ) {
				$sets[ $t ][ (int) $r->object_id ] = true;
			}
		}
		$counts = array();
		foreach ( $sets as $t => $ids ) {
			$counts[ $t ] = count( $ids );
		}
		self::$cache['counts'] = $counts;

		return $counts;
	}

	/**
	 * @param int $term Category.
	 * @return array<int,int> Its ancestors, nearest first.
	 */
	private static function ancestors( $term ) {
		$key = 'anc' . (int) $term;
		if ( ! isset( self::$cache[ $key ] ) ) {
			self::$cache[ $key ] = array_map( 'intval', get_ancestors( (int) $term, 'product_cat', 'taxonomy' ) );
		}

		return self::$cache[ $key ];
	}

	/**
	 * A category's date: its own entry, else its nearest ancestor's, else $fallback.
	 *
	 * @param int               $term     Category.
	 * @param array<int,string> $map      Map.
	 * @param string            $fallback Other goods.
	 * @return string
	 */
	private static function date_of_category( $term, array $map, $fallback ) {
		foreach ( array_merge( array( (int) $term ), self::ancestors( $term ) ) as $t ) {
			if ( isset( $map[ $t ] ) ) {
				return $map[ $t ];
			}
		}

		return $fallback;
	}

	/**
	 * Rows for Settings → Dates: categories with products, parents before children, then Other goods.
	 *
	 * @return array<int,array{term_id:int,name:string,parent:int,depth:int,count:int,date:string,set:bool,inherited_from:int|string,check:bool}>
	 */
	public static function rows() {
		$d      = self::get();
		$map    = $d ? $d['map'] : self::suggest()[0];
		$other  = $d ? $d['other'] : self::CHOICES[0];
		// Categories to check (CX-SID-94): only while the Dates are suggested. Saved or taken Dates are the owner's or
		// the shop's own; nothing is asked about them again.
		$check  = ( ! $d || 'suggested' === $d['state'] ) ? self::suggest()[2] : array();
		$counts = self::category_counts();
		$rows   = array();
		foreach ( self::categories( true ) as $t ) {
			$id   = (int) $t->term_id;
			$from = 'other';
			foreach ( array_merge( array( $id ), self::ancestors( $id ) ) as $a ) {
				if ( isset( $map[ $a ] ) ) {
					$from = $a;
					break;
				}
			}
			$rows[] = array(
				'term_id'        => $id,
				'name'           => (string) $t->name,
				'parent'         => (int) $t->parent,
				'depth'          => isset( self::$cache['depth'][ $id ] ) ? (int) self::$cache['depth'][ $id ] : 0,
				'count'          => isset( $counts[ $id ] ) ? (int) $counts[ $id ] : 0,
				'date'           => self::date_of_category( $id, $map, $other ),
				'set'            => $from === $id,
				'inherited_from' => $from === $id ? 0 : $from,
				'check'          => isset( $check[ $id ] ),
			);
		}
		$rows[] = array(
			'term_id'        => 0,
			'name'           => 'other',
			'parent'         => 0,
			'depth'          => 0,
			'count'          => self::other_count(),
			'date'           => $other,
			'set'            => true,
			'inherited_from' => 0,
			'check'          => false,
		);

		return $rows;
	}

	/**
	 * Products in no category except the default one.
	 *
	 * @return int
	 */
	private static function other_count() {
		global $wpdb;
		$default = (int) get_option( 'default_product_cat', 0 );
		$in      = "'" . implode( "','", array_map( 'esc_sql', self::STATUSES ) ) . "'";

		// phpcs:ignore WordPress.DB
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} p WHERE p.post_type = 'product' AND p.post_status IN ({$in})
				AND NOT EXISTS ( SELECT 1 FROM {$wpdb->term_relationships} tr
					JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'product_cat'
					WHERE tr.object_id = p.ID AND tt.term_id <> %d )",
				$default
			)
		);
	}

	/**
	 * Categories of a product (the product itself, or a variation's parent), without the default one.
	 *
	 * get_the_terms, not wp_get_post_terms: only it reads WordPress's object term cache, so a list that primed it
	 * (update_object_term_cache, as Status does for a page) asks the database nothing here.
	 *
	 * @param int $post Product.
	 * @return array<int,int>
	 */
	private static function product_categories( $post ) {
		$key = 'pc' . (int) $post;
		if ( ! isset( self::$cache[ $key ] ) ) {
			$terms   = get_the_terms( (int) $post, 'product_cat' );
			$ids     = is_array( $terms ) ? wp_list_pluck( $terms, 'term_id' ) : array();
			$default = (int) get_option( 'default_product_cat', 0 );
			self::$cache[ $key ] = array_values( array_diff( array_map( 'intval', $ids ), array( $default ) ) );
		}

		return self::$cache[ $key ];
	}

	// ----------------------------------------------------------------------------------------------------------------
	// The date of a product
	// ----------------------------------------------------------------------------------------------------------------

	/**
	 * The date of a product's or variation's bazna cijena.
	 *
	 * @param int $id Product or variation.
	 * @return array{date:string,from:string,rule_date:string,rule_from:string,two_categories:bool}
	 *         date as stored ("10.09.2026."); from: own (stored on the product), category, added (the day it was added
	 *         is later than its category's date), other (no category). rule_*: the same without the own date.
	 */
	public static function date_for( $id ) {
		$id   = (int) $id;
		$rule = self::rule_for( $id );
		$own  = trim( (string) get_post_meta( $id, CX_SIDRENA_META_DATE, true ) );
		$out  = array(
			'date'           => $rule['date'],
			'from'           => $rule['from'],
			'rule_date'      => $rule['date'],
			'rule_from'      => $rule['from'],
			'two_categories' => $rule['two'],
		);
		if ( '' !== $own ) {
			$parsed      = CX_Sidrena_History::parse_date( $own );
			$out['date'] = '' !== $parsed ? $parsed : $own;
			$out['from'] = 'own';
			// Stored by a fill that followed the rule, and still what the rule says: say where the rule took it from.
			// A date kept when the Dates changed (or set by a person) is the product's own.
			$izvor = get_post_meta( $id, CX_Sidrena_History::IZVOR_META, true );
			if ( $out['date'] === $rule['date'] && is_array( $izvor ) && in_array( isset( $izvor['date_from'] ) ? $izvor['date_from'] : '', array( 'category', 'added', 'other' ), true ) ) {
				$out['from'] = (string) $izvor['date_from'];
			}
		}

		return $out;
	}

	/**
	 * The rule alone (spec §1.3).
	 *
	 * @param int $id Product or variation.
	 * @return array{date:string,from:string,two:bool}
	 */
	private static function rule_for( $id ) {
		$d = self::get();
		if ( ! $d ) {
			// Not set up yet (first request after an update): MJDD's single reference date, as before.
			return array(
				'date' => self::legacy_date(),
				'from' => 'other',
				'two'  => false,
			);
		}
		$post = (int) ( 'product_variation' === get_post_type( $id ) ? wp_get_post_parent_id( $id ) : $id );
		$cats = self::product_categories( $post );
		$have = array();
		foreach ( $cats as $t ) {
			$have[ self::date_of_category( $t, $d['map'], $d['other'] ) ] = true;
		}
		$have = array_keys( $have );
		usort(
			$have,
			static function ( $a, $b ) {
				return self::ymd( $a ) - self::ymd( $b );
			}
		);
		$date = $have ? $have[0] : $d['other'];
		$from = $have ? 'category' : 'other';

		$added = self::added_date( $id );
		if ( '' !== $added && self::ymd( $added ) > self::ymd( $date ) ) {
			$date = $added;
			$from = 'added';
		}

		return array(
			'date' => $date,
			'from' => $from,
			'two'  => count( $have ) > 1,
		);
	}

	/**
	 * The day the product or variation was added, in the shop's time zone.
	 *
	 * @param int $id Product or variation.
	 * @return string "15.11.2026.", or empty.
	 */
	public static function added_date( $id ) {
		$post = get_post( (int) $id ); // not cached here: a product's date may be changed in the same request
		if ( ! $post ) {
			return '';
		}
		// A draft has no GMT date yet ("0000-00-00 00:00:00"; Argus B1: then the day added fell out of the rule and a
		// new product saved first as a draft got its category's date). Its local date is the shop's time of the save,
		// the same as CX_Sidrena_Product_Meta::existed_before_install() reads.
		$ts = '0000-00-00 00:00:00' !== $post->post_date_gmt ? get_post_time( 'U', true, $post ) : get_post_timestamp( $post );

		return $ts ? wp_date( 'd.m.Y.', (int) $ts ) : '';
	}

	/**
	 * @param string $date "10.09.2026.".
	 * @return int 20260910, or 0.
	 */
	private static function ymd( $date ) {
		return preg_match( '/^(\d{1,2})\.(\d{1,2})\.(\d{4})\.?$/', trim( (string) $date ), $m ) ? (int) sprintf( '%04d%02d%02d', $m[3], $m[2], $m[1] ) : 0;
	}

	/**
	 * Whether saving this product now gives it a bazna: none yet, it has a regular price, it can have one (not a
	 * variable or grouped parent), and it was added after CX Sidrena was installed (an old product without a bazna
	 * never gets today's price and date, it goes to Needs attention).
	 *
	 * @param WC_Product $product Product or variation.
	 * @return bool
	 */
	public static function fills_on_save( $product ) {
		if ( ! $product instanceof WC_Product || ! $product->get_id() ) {
			return false;
		}
		if ( $product->is_type( array( 'variable', 'grouped', 'variable-subscription' ) ) ) {
			return false;
		}
		if ( ! CX_Sidrena_Settings::instance()->is_auto_fill_enabled() ) {
			return false;
		}
		if ( '' !== CX_Sidrena_Product_Meta::instance()->get_price( $product->get_id() ) ) {
			return false;
		}

		return self::is_new( $product->get_id() );
	}

	/**
	 * Added after CX Sidrena was first installed on this shop: the same test as the early stop in the fill
	 * (CX_Sidrena_Product_Meta::existed_before_install, CX-SID-23), so Status and the fill never disagree.
	 *
	 * @param int $id Product or variation.
	 * @return bool
	 */
	public static function is_new( $id ) {
		return ! CX_Sidrena_Product_Meta::existed_before_install( (int) $id );
	}

	/**
	 * Products in two categories with different dates and no own date (Status: "Two categories, two dates").
	 * A variable product counts once.
	 *
	 * @param int $page     From 1.
	 * @param int $per_page Per page.
	 * @return array{ids:array<int,int>,total:int}
	 */
	public static function two_categories( $page = 1, $per_page = 50 ) {
		global $wpdb;
		$in  = "'" . implode( "','", array_map( 'esc_sql', self::STATUSES ) ) . "'";
		$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB
			"SELECT tr.object_id FROM {$wpdb->term_relationships} tr
			JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'product_cat'
			JOIN {$wpdb->posts} p ON p.ID = tr.object_id AND p.post_type = 'product' AND p.post_status IN ({$in})
			GROUP BY tr.object_id HAVING COUNT(*) > 1 ORDER BY tr.object_id"
		);
		$ids = array_map( 'intval', (array) $ids );
		update_object_term_cache( $ids, 'product' ); // categories and types of all of them in one query
		$hit = array();
		foreach ( $ids as $id ) {
			$id = (int) $id;
			if ( ! self::rule_for( $id )['two'] ) {
				continue;
			}
			$type = get_the_terms( $id, 'product_type' );
			$var  = is_array( $type ) && in_array( $type[0]->slug, array( 'variable', 'variable-subscription' ), true );
			if ( $var ) {
				$kids = get_posts( array( 'post_type' => 'product_variation', 'post_parent' => $id, 'post_status' => 'any', 'fields' => 'ids', 'numberposts' => -1 ) );
				$own  = false === array_search( '', array_map( static function ( $k ) { return trim( (string) get_post_meta( $k, CX_SIDRENA_META_DATE, true ) ); }, $kids ), true );
				if ( $kids && $own ) {
					continue; // every size has its own date
				}
			} elseif ( '' !== trim( (string) get_post_meta( $id, CX_SIDRENA_META_DATE, true ) ) ) {
				continue;
			}
			$hit[] = $id;
		}
		$per_page = max( 1, (int) $per_page );

		return array(
			'ids'   => array_slice( $hit, ( max( 1, (int) $page ) - 1 ) * $per_page, $per_page ),
			'total' => count( $hit ),
		);
	}

	/**
	 * Of these, the ones with no own date.
	 *
	 * @param array<int,array{id:int,cat_post:int,own:string}> $products From bazna_products().
	 * @return array<int,array{id:int,cat_post:int,own:string}>
	 */
	private static function no_own( array $products ) {
		return array_filter(
			$products,
			static function ( $p ) {
				return '' === $p['own'];
			}
		);
	}

	/**
	 * Products and variations with a bazna: id, the post whose categories count, own date.
	 *
	 * @return array<int,array{id:int,cat_post:int,own:string}>
	 */
	private static function bazna_products() {
		global $wpdb;
		$in   = "'" . implode( "','", array_map( 'esc_sql', array_merge( self::STATUSES, array( 'inherit' ) ) ) ) . "'";
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT p.ID, p.post_type, p.post_parent, dt.meta_value AS own FROM {$wpdb->posts} p
				JOIN {$wpdb->postmeta} pr ON pr.post_id = p.ID AND pr.meta_key = %s AND pr.meta_value <> ''
				LEFT JOIN {$wpdb->postmeta} dt ON dt.post_id = p.ID AND dt.meta_key = %s
				WHERE p.post_type IN ('product','product_variation') AND p.post_status IN ({$in}) ORDER BY p.ID",
				CX_SIDRENA_META_PRICE,
				CX_SIDRENA_META_DATE
			)
		);
		$out = array();
		foreach ( (array) $rows as $r ) {
			$own   = trim( (string) $r->own );
			$p     = CX_Sidrena_History::parse_date( $own );
			$out[] = array(
				'id'       => (int) $r->ID,
				'cat_post' => 'product_variation' === $r->post_type ? (int) $r->post_parent : (int) $r->ID,
				'own'      => '' !== $p ? $p : $own,
			);
		}

		return $out;
	}
}

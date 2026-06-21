<?php
/**
 * Plugin Name: Zibll 商城迁移助手
 * Plugin URI: https://www.maotk.com/
 * Description: 安全迁移 Zibll 商城商品、完整多值 Meta、分类层级、特色图、正文图片和 Meta 图片。
 * Version: 6.1.0
 * Author: Mao TK
 * Author URI: https://www.maotk.com/
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * License: GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MaoTK_Zibll_Shop_Migrator {
	const VERSION            = '6.1.0';
	const PAGE               = 'maotk-zibll-shop-migrator';
	const BRAND_URL          = 'https://www.maotk.com/';
	const BRAND_LOGO         = 'https://www.maotk.com/wp-content/uploads/maotk-favicon.svg';
	const POST_TYPE          = 'shop_product';
	const SOURCE_META        = '_maotk_zibll_migrator_source';
	const IMAGE_HASH_META    = '_maotk_zibll_migrator_sha256';
	const MANAGED_IMAGE_META = '_maotk_zibll_migrator_managed';
	const MAX_PACKAGE_MB     = 1500;
	const MAX_MANIFEST_MB    = 150;
	const MAX_IMAGE_MB       = 50;
	const MAX_PACKAGE_FILES  = 50000;

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
		add_action( 'admin_post_maotk_zsm_export', array( __CLASS__, 'export' ) );
		add_action( 'admin_post_maotk_zsm_import', array( __CLASS__, 'import' ) );
		add_action( 'wp_ajax_maotk_zsm_progress', array( __CLASS__, 'ajax_progress' ) );
		add_filter( 'plugin_row_meta', array( __CLASS__, 'plugin_row_meta' ), 10, 2 );
	}

	public static function admin_menu() {
		add_management_page(
			'Zibll 商城迁移',
			'Zibll 商城迁移',
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'render_page' )
		);
	}

	public static function plugin_row_meta( $links, $file ) {
		if ( plugin_basename( __FILE__ ) === $file ) {
			$links[] = '<a href="' . esc_url( self::BRAND_URL ) . '" target="_blank" rel="noopener noreferrer">访问 Mao TK</a>';
		}
		return $links;
	}

	private static function require_access() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '你没有执行此操作的权限。' );
		}
	}

	public static function ajax_progress() {
		self::require_access();
		check_ajax_referer( 'maotk_zsm_progress', 'nonce' );
		$token = isset( $_POST['token'] ) ? sanitize_key( wp_unslash( $_POST['token'] ) ) : '';
		$data  = $token ? get_transient( self::progress_key( $token ) ) : false;
		wp_send_json_success( $data ? $data : array( 'status' => 'pending' ) );
	}

	private static function progress_key( $token ) {
		return 'maotk_zsm_progress_' . get_current_user_id() . '_' . $token;
	}

	private static function set_progress( $token, $completed, $total, $stage, $current = '', $status = 'running' ) {
		if ( ! $token ) return;
		$old = get_transient( self::progress_key( $token ) );
		set_transient(
			self::progress_key( $token ),
			array(
				'status' => $status,
				'completed' => max( 0, (int) $completed ),
				'total' => max( 1, (int) $total ),
				'stage' => sanitize_text_field( $stage ),
				'current' => sanitize_text_field( $current ),
				'started_at' => is_array( $old ) && ! empty( $old['started_at'] ) ? (float) $old['started_at'] : microtime( true ),
				'updated_at' => microtime( true ),
			),
			HOUR_IN_SECONDS
		);
	}

	public static function render_page() {
		self::require_access();
		$products     = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => array( 'publish', 'private', 'draft', 'pending', 'future' ),
				'posts_per_page' => -1,
				'orderby'        => 'ID',
				'order'          => 'DESC',
			)
		);
		$counts       = wp_count_posts( self::POST_TYPE, 'readable' );
		$export_token = wp_generate_password( 20, false, false );
		$import_token = wp_generate_password( 20, false, false );
		$progress_nonce = wp_create_nonce( 'maotk_zsm_progress' );

		if ( ! post_type_exists( self::POST_TYPE ) ) {
			echo '<div class="notice notice-error"><p>当前网站没有注册 <code>shop_product</code> 商品类型。请先启用包含 Zibll 商城功能的主题或插件。</p></div>';
		}
		if ( ! class_exists( 'ZipArchive' ) ) {
			echo '<div class="notice notice-error"><p>服务器未启用 PHP ZipArchive 扩展，无法创建或读取迁移包。</p></div>';
		}
		self::render_result();
		?>
		<div class="wrap">
			<h1 style="display:flex;align-items:center;gap:10px">
				<a href="<?php echo esc_url( self::BRAND_URL ); ?>" target="_blank" rel="noopener noreferrer">
					<img src="<?php echo esc_url( self::BRAND_LOGO ); ?>" alt="Mao TK" width="38" height="38">
				</a>
				Zibll 商城迁移助手
			</h1>
			<p>迁移商品基础信息、完整多值 Meta、分类层级、特色图、正文图片和 Meta 中引用的媒体。</p>

			<div class="notice notice-warning inline" style="max-width:1100px">
				<p><strong>重要：</strong>请确保新旧网站使用兼容版本的 Zibll 商城结构。导入前务必备份新站数据库和 <code>wp-content/uploads</code>。</p>
			</div>

			<div class="card" style="max-width:1100px;padding:8px 20px 20px">
				<h2>第一步：在旧网站选择并导出</h2>
				<form id="maotk-zsm-export-form" method="post" target="maotk-zsm-download-frame" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="maotk_zsm_export">
					<input type="hidden" name="export_token" value="<?php echo esc_attr( $export_token ); ?>">
					<?php wp_nonce_field( 'maotk_zsm_export' ); ?>

					<h3>1. 选择迁移内容</h3>
					<div style="display:grid;gap:8px;margin-bottom:16px">
						<label><input type="checkbox" name="fields[]" value="meta" checked> <strong>完整商品 Meta</strong>：价格、库存、销量、属性、购买设置等；保留同一字段的多个值</label>
						<label><input type="checkbox" name="fields[]" value="terms" checked> <strong>分类和标签</strong>：保留名称、别名、描述和父子层级</label>
						<label><input type="checkbox" name="fields[]" value="media" checked> <strong>商品图片</strong>：特色图、正文图片及图片相关 Meta 中的附件</label>
					</div>

					<h3>2. 按状态筛选</h3>
					<div id="maotk-zsm-statuses" style="display:flex;flex-wrap:wrap;gap:8px 18px;margin-bottom:16px">
						<label><input type="checkbox" class="maotk-zsm-status" value="all" checked> 全部状态</label>
						<label><input type="checkbox" class="maotk-zsm-status" value="publish"> 已发布（<?php echo self::count_status( $counts, 'publish' ); ?>）</label>
						<label><input type="checkbox" class="maotk-zsm-status" value="private"> 私密（<?php echo self::count_status( $counts, 'private' ); ?>）</label>
						<label><input type="checkbox" class="maotk-zsm-status" value="draft"> 草稿（<?php echo self::count_status( $counts, 'draft' ); ?>）</label>
						<label><input type="checkbox" class="maotk-zsm-status" value="pending"> 待审（<?php echo self::count_status( $counts, 'pending' ); ?>）</label>
						<label><input type="checkbox" class="maotk-zsm-status" value="future"> 定时（<?php echo self::count_status( $counts, 'future' ); ?>）</label>
					</div>

					<h3>3. 选择商品</h3>
					<div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:10px">
						<input id="maotk-zsm-search" type="search" placeholder="搜索商品标题、别名或 ID" style="min-width:320px">
						<button type="button" class="button" id="maotk-zsm-select-visible">选择当前结果</button>
						<button type="button" class="button" id="maotk-zsm-clear-visible">取消当前结果</button>
						<button type="button" class="button" id="maotk-zsm-select-all">全选</button>
						<button type="button" class="button" id="maotk-zsm-clear-all">取消全选</button>
						<strong id="maotk-zsm-selected-count" style="align-self:center"></strong>
					</div>
					<div style="max-height:460px;overflow:auto;border:1px solid #c3c4c7">
						<table class="widefat striped">
							<thead><tr><th style="width:38px"></th><th>ID</th><th>标题</th><th>状态</th><th>发布日期</th><th>特色图</th></tr></thead>
							<tbody>
							<?php foreach ( $products as $product ) : ?>
								<?php
								$thumb  = get_the_post_thumbnail_url( $product->ID, 'thumbnail' );
								$search = strtolower( $product->ID . ' ' . $product->post_title . ' ' . $product->post_name );
								?>
								<tr class="maotk-zsm-row" data-status="<?php echo esc_attr( $product->post_status ); ?>" data-search="<?php echo esc_attr( $search ); ?>">
									<td><input type="checkbox" class="maotk-zsm-check" name="product_ids[]" value="<?php echo (int) $product->ID; ?>" checked></td>
									<td><?php echo (int) $product->ID; ?></td>
									<td><strong><?php echo esc_html( $product->post_title ); ?></strong><br><code><?php echo esc_html( $product->post_name ); ?></code></td>
									<td><?php echo esc_html( $product->post_status ); ?></td>
									<td><?php echo esc_html( $product->post_date ); ?></td>
									<td><?php echo $thumb ? '<img src="' . esc_url( $thumb ) . '" alt="" width="45" height="45" style="object-fit:cover">' : '无'; ?></td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					</div>
					<p class="description">筛选和搜索只控制列表显示；最终只导出被勾选的商品。</p>
					<?php submit_button( '下载商品迁移包', 'primary', 'submit', false ); ?>
				</form>
			</div>

			<div class="card" style="max-width:1100px;padding:8px 20px 20px;margin-top:20px">
				<h2>第二步：在新网站导入</h2>
				<form id="maotk-zsm-import-form" method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="maotk_zsm_import">
					<input type="hidden" name="progress_token" value="<?php echo esc_attr( $import_token ); ?>">
					<?php wp_nonce_field( 'maotk_zsm_import' ); ?>
					<p><input type="file" name="migration_package" accept=".zip,application/zip" required></p>
					<p>
						<label><input type="checkbox" name="update_existing" value="1" checked> <strong>覆盖之前导入或别名相同的商品</strong></label>
						<br><span class="description">推荐勾选。会更新商品、Meta、分类和图片，不会因为标题相同而误跳过。</span>
					</p>
					<p>
						<label><input type="checkbox" name="preserve_status" value="1" checked> <strong>保留原商品状态</strong></label>
						<br><span class="description">取消后，所有导入商品统一保存为草稿。</span>
					</p>
					<?php submit_button( '开始导入', 'primary', 'submit', false ); ?>
				</form>
			</div>

			<iframe name="maotk-zsm-download-frame" style="display:none" title="商品迁移包下载"></iframe>
			<div id="maotk-zsm-progress" style="display:none;position:fixed;z-index:100000;inset:0;background:rgba(0,0,0,.48);align-items:center;justify-content:center">
				<div style="width:min(580px,calc(100vw - 40px));background:#fff;border-radius:8px;padding:24px;box-shadow:0 12px 50px rgba(0,0,0,.28)">
					<h2 id="maotk-zsm-progress-title" style="margin-top:0">正在处理</h2>
					<p id="maotk-zsm-progress-text">请不要关闭页面。</p>
					<div style="height:18px;background:#e5e7eb;border-radius:999px;overflow:hidden"><div id="maotk-zsm-progress-bar" style="height:100%;width:0;background:#2271b1;border-radius:999px;transition:width .35s ease"></div></div>
					<p style="display:flex;justify-content:space-between;margin:10px 0 0"><strong id="maotk-zsm-progress-percent">0%</strong><span id="maotk-zsm-progress-eta">预计剩余：计算中</span></p>
					<p id="maotk-zsm-progress-time" style="color:#646970;margin-bottom:0">已用时：0 秒</p>
				</div>
			</div>
		</div>
		<script>
		(function () {
			const rows = Array.from(document.querySelectorAll('.maotk-zsm-row'));
			const checks = Array.from(document.querySelectorAll('.maotk-zsm-check'));
			const statusChecks = Array.from(document.querySelectorAll('.maotk-zsm-status'));
			const search = document.getElementById('maotk-zsm-search');
			const count = document.getElementById('maotk-zsm-selected-count');
			const overlay = document.getElementById('maotk-zsm-progress');
			const pTitle = document.getElementById('maotk-zsm-progress-title');
			const pText = document.getElementById('maotk-zsm-progress-text');
			const pBar = document.getElementById('maotk-zsm-progress-bar');
			const pTime = document.getElementById('maotk-zsm-progress-time');
			const pPercent = document.getElementById('maotk-zsm-progress-percent');
			const pEta = document.getElementById('maotk-zsm-progress-eta');
			const ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
			const progressNonce = <?php echo wp_json_encode( $progress_nonce ); ?>;
			let timer = null;
			let progressPoll = null;
			let startedAt = 0;

			function updateCount() {
				count.textContent = '已选择 ' + checks.filter(c => c.checked).length + ' / ' + checks.length + ' 个商品';
			}
			function filterRows() {
				const statuses = statusChecks.filter(c => c.checked).map(c => c.value);
				const all = statuses.includes('all') || !statuses.length;
				const keyword = search.value.trim().toLowerCase();
				rows.forEach(row => {
					const show = (all || statuses.includes(row.dataset.status)) && (!keyword || row.dataset.search.includes(keyword));
					row.style.display = show ? '' : 'none';
				});
			}
			statusChecks.forEach(box => box.addEventListener('change', function () {
				if (this.value === 'all' && this.checked) statusChecks.filter(c => c !== this).forEach(c => c.checked = false);
				if (this.value !== 'all' && this.checked) statusChecks.find(c => c.value === 'all').checked = false;
				filterRows();
			}));
			search.addEventListener('input', filterRows);
			checks.forEach(c => c.addEventListener('change', updateCount));
			document.getElementById('maotk-zsm-select-visible').addEventListener('click', () => { rows.filter(r => r.style.display !== 'none').forEach(r => r.querySelector('.maotk-zsm-check').checked = true); updateCount(); });
			document.getElementById('maotk-zsm-clear-visible').addEventListener('click', () => { rows.filter(r => r.style.display !== 'none').forEach(r => r.querySelector('.maotk-zsm-check').checked = false); updateCount(); });
			document.getElementById('maotk-zsm-select-all').addEventListener('click', () => { checks.forEach(c => c.checked = true); updateCount(); });
			document.getElementById('maotk-zsm-clear-all').addEventListener('click', () => { checks.forEach(c => c.checked = false); updateCount(); });
			updateCount();

			function cookieValue(name) {
				const match = document.cookie.match(new RegExp('(?:^|; )' + name.replace(/([.$?*|{}()[\]\\/+^])/g, '\\$1') + '=([^;]*)'));
				return match ? decodeURIComponent(match[1]) : '';
			}
			function clearCookie(name) {
				document.cookie = name + '=; Max-Age=0; path=<?php echo esc_js( COOKIEPATH ? COOKIEPATH : '/' ); ?>; SameSite=Lax';
			}
			function formatDuration(seconds) {
				seconds = Math.max(0, Math.round(seconds));
				if (seconds < 60) return seconds + ' 秒';
				const minutes = Math.floor(seconds / 60), remain = seconds % 60;
				if (minutes < 60) return minutes + ' 分 ' + remain + ' 秒';
				return Math.floor(minutes / 60) + ' 小时 ' + (minutes % 60) + ' 分';
			}
			async function readProgress(token) {
				try {
					const body = new URLSearchParams({action:'maotk_zsm_progress',nonce:progressNonce,token:token});
					const response = await fetch(ajaxUrl,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},body:body.toString()});
					const json = await response.json();
					if (!json.success || !json.data || json.data.status === 'pending') return;
					const data = json.data, total = Math.max(1, Number(data.total)||1), completed = Math.max(0, Number(data.completed)||0);
					const percent = data.status === 'finished' ? 100 : Math.min(99, Math.floor(completed / total * 100));
					pBar.style.width = percent + '%';
					pPercent.textContent = percent + '%（' + completed + ' / ' + total + '）';
					pText.textContent = (data.stage || '正在处理') + (data.current ? '：' + data.current : '');
					const elapsed = Math.max(1, Date.now()/1000 - (Number(data.started_at)||startedAt/1000));
					const rate = completed / elapsed;
					pEta.textContent = rate > 0 && completed < total ? '预计剩余：' + formatDuration((total-completed)/rate) : (completed >= total ? '预计剩余：0 秒' : '预计剩余：计算中');
				} catch (error) {
					pEta.textContent = '预计剩余：等待服务器进度';
				}
			}
			function startProgress(mode, token) {
				let seconds = 0;
				startedAt = Date.now();
				overlay.style.display = 'flex';
				pTitle.textContent = mode === 'export' ? '正在导出商品' : '正在导入商品';
				pText.textContent = mode === 'export' ? '正在收集商品、Meta、分类和图片并生成 ZIP。' : '正在写入商品、Meta、分类和媒体，请不要刷新页面。';
				pBar.style.width = '0%';
				pPercent.textContent = '0%';
				pEta.textContent = '预计剩余：计算中';
				timer = setInterval(() => {
					seconds++;
					pTime.textContent = '已用时：' + formatDuration(seconds);
				}, 1000);
				progressPoll = setInterval(() => readProgress(token), 800);
				readProgress(token);
			}
			document.getElementById('maotk-zsm-export-form').addEventListener('submit', function (event) {
				if (!checks.some(c => c.checked)) {
					event.preventDefault();
					alert('请至少选择一个商品。');
					return;
				}
				const token = this.querySelector('[name="export_token"]').value;
				const cookie = 'maotk_zsm_export_' + token;
				clearCookie(cookie);
				startProgress('export', token);
				const poll = setInterval(() => {
					const result = cookieValue(cookie);
					if (result.indexOf('done-') === 0) {
						clearInterval(poll);
						clearCookie(cookie);
						clearInterval(timer);
						clearInterval(progressPoll);
						pBar.style.width = '100%';
						const count = parseInt(result.substring(5), 10) || 0;
						pPercent.textContent = '100%（' + count + ' / ' + count + '）';
						pEta.textContent = '预计剩余：0 秒';
						pTitle.textContent = '导出完成';
						pText.textContent = '已导出 ' + count + ' 个商品，浏览器应已开始下载。';
						setTimeout(() => overlay.style.display = 'none', 2600);
					}
				}, 700);
			});
			document.getElementById('maotk-zsm-import-form').addEventListener('submit', function () { startProgress('import', this.querySelector('[name="progress_token"]').value); });
		}());
		</script>
		<?php
	}

	private static function count_status( $counts, $status ) {
		return isset( $counts->{$status} ) ? (int) $counts->{$status} : 0;
	}

	private static function render_result() {
		$result = get_transient( 'maotk_zsm_result_' . get_current_user_id() );
		if ( ! $result ) {
			return;
		}
		delete_transient( 'maotk_zsm_result_' . get_current_user_id() );
		$failed_products = isset( $result['failed_products'] ) ? (array) $result['failed_products'] : array();
		$failed_images   = isset( $result['failed_images'] ) ? (array) $result['failed_images'] : array();
		$warnings        = isset( $result['warnings'] ) ? (array) $result['warnings'] : array();
		$problems        = count( $failed_products ) + count( $failed_images ) + count( $warnings );

		echo '<div class="notice ' . ( $problems ? 'notice-warning' : 'notice-success' ) . ' is-dismissible"><p><strong>';
		echo esc_html(
			sprintf(
				'导入完成：新增商品 %1$d，覆盖商品 %2$d，跳过 %3$d，新增图片 %4$d，商品失败 %5$d，图片失败 %6$d，其他警告 %7$d。',
				isset( $result['created'] ) ? (int) $result['created'] : 0,
				isset( $result['updated'] ) ? (int) $result['updated'] : 0,
				isset( $result['skipped'] ) ? (int) $result['skipped'] : 0,
				isset( $result['images'] ) ? (int) $result['images'] : 0,
				count( $failed_products ),
				count( $failed_images ),
				count( $warnings )
			)
		);
		echo '</strong></p>';
		if ( $failed_products ) {
			echo '<details open><summary><strong>失败商品（' . count( $failed_products ) . '）</strong></summary><ul>';
			foreach ( $failed_products as $failure ) {
				echo '<li><strong>' . esc_html( isset( $failure['title'] ) ? $failure['title'] : '未命名商品' ) . '</strong>：' . esc_html( isset( $failure['reason'] ) ? $failure['reason'] : '未知原因' ) . '</li>';
			}
			echo '</ul></details>';
		}
		if ( $failed_images ) {
			echo '<details open><summary><strong>失败图片（' . count( $failed_images ) . '）</strong></summary><ul>';
			foreach ( $failed_images as $failure ) {
				echo '<li><strong>' . esc_html( isset( $failure['product'] ) ? $failure['product'] : '未知商品' ) . '</strong>：' . esc_html( isset( $failure['reason'] ) ? $failure['reason'] : '未知原因' );
				if ( ! empty( $failure['url'] ) ) {
					echo '<br><code style="word-break:break-all">' . esc_html( $failure['url'] ) . '</code>';
				}
				echo '</li>';
			}
			echo '</ul></details>';
		}
		if ( $warnings ) {
			echo '<details><summary><strong>其他警告（' . count( $warnings ) . '）</strong></summary><ul>';
			foreach ( $warnings as $warning ) {
				echo '<li>' . esc_html( $warning ) . '</li>';
			}
			echo '</ul></details>';
		}
		if ( ! $problems ) {
			echo '<p>全部所选商品、Meta、分类和已打包图片均导入成功。</p>';
		}
		echo '</div>';
	}

	public static function export() {
		self::require_access();
		check_admin_referer( 'maotk_zsm_export' );
		self::prepare_long_task();
		if ( ! post_type_exists( self::POST_TYPE ) ) {
			wp_die( '当前网站没有注册 shop_product 商品类型。' );
		}
		if ( ! class_exists( 'ZipArchive' ) ) {
			wp_die( '服务器未启用 PHP ZipArchive 扩展。' );
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';

		$product_ids = isset( $_POST['product_ids'] ) ? array_values( array_unique( array_filter( array_map( 'absint', (array) wp_unslash( $_POST['product_ids'] ) ) ) ) ) : array();
		if ( ! $product_ids ) {
			wp_die( '请至少选择一个商品。', '未选择商品', array( 'back_link' => true ) );
		}
		$allowed_fields = array( 'meta', 'terms', 'media' );
		$fields         = isset( $_POST['fields'] ) ? array_values( array_intersect( $allowed_fields, array_map( 'sanitize_key', (array) wp_unslash( $_POST['fields'] ) ) ) ) : array();
		$progress_token = isset( $_POST['export_token'] ) ? sanitize_key( wp_unslash( $_POST['export_token'] ) ) : '';
		$progress_total = max( 1, count( $product_ids ) );
		$progress_done  = 0;
		self::set_progress( $progress_token, 0, $progress_total, '正在准备商品数据' );
		$tmp_zip        = wp_tempnam( 'zibll-shop-migration.zip' );
		if ( ! $tmp_zip ) {
			wp_die( '无法创建临时迁移包。' );
		}
		$zip = new ZipArchive();
		if ( true !== $zip->open( $tmp_zip, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			@unlink( $tmp_zip );
			wp_die( '无法创建 ZIP 迁移包。' );
		}

		$manifest = array(
			'format'      => 'maotk-zibll-shop-migrator',
			'version'     => self::VERSION,
			'exported_at' => gmdate( 'c' ),
			'source_url'  => home_url( '/' ),
			'fields'      => $fields,
			'products'    => array(),
			'assets'      => array(),
		);
		$packed = array();

		foreach ( $product_ids as $product_id ) {
			$post = get_post( $product_id );
			if ( ! $post || self::POST_TYPE !== $post->post_type ) {
				++$progress_done;
				self::set_progress( $progress_token, $progress_done, $progress_total, '正在导出商品', '无效商品 ID：' . $product_id );
				continue;
			}
			self::set_progress( $progress_token, $progress_done, $progress_total, '正在打包商品、Meta 和图片', $post->post_title );
			$raw_meta       = in_array( 'meta', $fields, true ) ? self::export_meta( $product_id ) : array();
			$attachment_map = array();
			$asset_urls     = array();
			$featured_id    = get_post_thumbnail_id( $product_id );
			$featured_url   = $featured_id ? wp_get_attachment_url( $featured_id ) : '';

			if ( in_array( 'media', $fields, true ) ) {
				$asset_urls = self::extract_image_urls( $post->post_content );
				if ( $featured_url ) {
					$asset_urls[]                 = $featured_url;
					$attachment_map[ $featured_id ] = $featured_url;
				}
				self::collect_meta_media( $raw_meta, $asset_urls, $attachment_map );
				$asset_urls = array_values( array_unique( array_filter( $asset_urls ) ) );
				foreach ( $asset_urls as $asset_url ) {
					self::pack_asset( $zip, $asset_url, $post->post_title, $manifest['assets'], $packed );
				}
			}

			$manifest['products'][] = array(
				'source_id'      => (int) $product_id,
				'basic'          => array(
					'post_title'      => $post->post_title,
					'post_content'    => $post->post_content,
					'post_excerpt'    => $post->post_excerpt,
					'post_status'     => $post->post_status,
					'post_name'       => $post->post_name,
					'post_date'       => $post->post_date,
					'post_date_gmt'   => $post->post_date_gmt,
					'post_modified'   => $post->post_modified,
					'comment_status'  => $post->comment_status,
					'ping_status'     => $post->ping_status,
					'post_password'   => $post->post_password,
					'menu_order'      => (int) $post->menu_order,
				),
				'meta'           => $raw_meta,
				'terms'          => in_array( 'terms', $fields, true ) ? self::export_terms( $product_id ) : array(),
				'featured_image' => $featured_url,
				'attachment_map' => $attachment_map,
				'asset_urls'     => $asset_urls,
			);
			++$progress_done;
			self::set_progress( $progress_token, $progress_done, $progress_total, '正在导出商品', $post->post_title );
		}

		$json = wp_json_encode( $manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( false === $json || ! $zip->addFromString( 'manifest.json', $json ) ) {
			$zip->close();
			@unlink( $tmp_zip );
			wp_die( '无法写入迁移清单。' );
		}
		$zip->close();
		self::set_progress( $progress_token, $progress_total, $progress_total, '导出完成', '', 'finished' );
		$token = $progress_token;
		if ( $token ) {
			setcookie(
				'maotk_zsm_export_' . $token,
				'done-' . count( $manifest['products'] ),
				array(
					'expires'  => time() + 300,
					'path'     => COOKIEPATH ? COOKIEPATH : '/',
					'secure'   => is_ssl(),
					'httponly' => false,
					'samesite' => 'Lax',
				)
			);
		}
		$filename = 'zibll-shop-migration-' . gmdate( 'Y-m-d-His' ) . '.zip';
		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . filesize( $tmp_zip ) );
		readfile( $tmp_zip );
		@unlink( $tmp_zip );
		exit;
	}

	private static function export_meta( $post_id ) {
		$meta = get_post_meta( $post_id );
		foreach ( self::excluded_meta_keys() as $key ) {
			unset( $meta[ $key ] );
		}
		$clean = array();
		foreach ( $meta as $key => $values ) {
			if ( ! is_string( $key ) || ! is_array( $values ) ) {
				continue;
			}
			$clean_values = array();
			foreach ( $values as $value ) {
				if ( is_scalar( $value ) || null === $value ) {
					$clean_values[] = (string) $value;
				}
			}
			if ( $clean_values ) {
				$clean[ $key ] = $clean_values;
			}
		}
		return $clean;
	}

	private static function excluded_meta_keys() {
		return array(
			'_edit_lock',
			'_edit_last',
			'_thumbnail_id',
			'_wp_old_slug',
			'_wp_trash_meta_status',
			'_wp_trash_meta_time',
			self::SOURCE_META,
		);
	}

	private static function export_terms( $post_id ) {
		$output = array();
		foreach ( get_object_taxonomies( self::POST_TYPE ) as $taxonomy ) {
			$assigned = wp_get_object_terms( $post_id, $taxonomy );
			if ( is_wp_error( $assigned ) || ! $assigned ) {
				continue;
			}
			$definitions = array();
			foreach ( $assigned as $term ) {
				self::add_export_term( $term, $taxonomy, true, $definitions );
			}
			$output[ $taxonomy ] = array_values( $definitions );
		}
		return $output;
	}

	private static function add_export_term( $term, $taxonomy, $assigned, &$definitions ) {
		if ( ! $term || is_wp_error( $term ) ) {
			return;
		}
		$parent = $term->parent ? get_term( $term->parent, $taxonomy ) : null;
		if ( $parent && ! is_wp_error( $parent ) ) {
			self::add_export_term( $parent, $taxonomy, false, $definitions );
		}
		if ( isset( $definitions[ $term->term_id ] ) ) {
			if ( $assigned ) {
				$definitions[ $term->term_id ]['assigned'] = true;
			}
			return;
		}
		$definitions[ $term->term_id ] = array(
			'name'        => $term->name,
			'slug'        => $term->slug,
			'description' => $term->description,
			'parent_slug' => $parent && ! is_wp_error( $parent ) ? $parent->slug : '',
			'assigned'    => (bool) $assigned,
		);
	}

	private static function collect_meta_media( $raw_meta, &$urls, &$attachment_map ) {
		foreach ( $raw_meta as $key => $values ) {
			foreach ( (array) $values as $raw_value ) {
				foreach ( self::extract_image_urls( (string) $raw_value ) as $url ) {
					$urls[] = $url;
				}
				if ( ! preg_match( '/(?:image|img|thumb|thumbnail|gallery|attachment|cover|poster|icon|logo)/i', $key ) ) {
					continue;
				}
				$decoded = self::safe_decode_meta( $raw_value );
				if ( is_wp_error( $decoded ) ) {
					continue;
				}
				$ids = array();
				self::collect_numeric_values( $decoded, $ids );
				foreach ( array_unique( $ids ) as $attachment_id ) {
					if ( 'attachment' !== get_post_type( $attachment_id ) ) {
						continue;
					}
					$url = wp_get_attachment_url( $attachment_id );
					if ( $url ) {
						$attachment_map[ $attachment_id ] = $url;
						$urls[]                           = $url;
					}
				}
			}
		}
	}

	private static function collect_numeric_values( $value, &$ids ) {
		if ( is_array( $value ) ) {
			foreach ( $value as $child ) {
				self::collect_numeric_values( $child, $ids );
			}
		} elseif ( is_int( $value ) || ( is_string( $value ) && ctype_digit( $value ) ) ) {
			$number = (int) $value;
			if ( $number > 0 ) {
				$ids[] = $number;
			}
		}
	}

	private static function extract_image_urls( $content ) {
		$urls = array();
		if ( ! is_string( $content ) || '' === $content ) {
			return $urls;
		}
		$patterns = array(
			'/\b(?:src|data-src|data-original|data-lazy-src)\s*=\s*(["\'])(.*?)\1/is',
			'/\bsrcset\s*=\s*(["\'])(.*?)\1/is',
			'/\burl\(\s*(["\']?)(.*?)\1\s*\)/is',
			'/https?:\/\/[^\s"\'<>]+?\.(?:jpe?g|png|gif|webp|avif|bmp|ico|svg)(?:\?[^\s"\'<>]*)?/i',
		);
		if ( preg_match_all( $patterns[0], $content, $matches ) ) {
			foreach ( $matches[2] as $url ) {
				$url = self::normalize_asset_url( $url );
				if ( $url ) {
					$urls[] = $url;
				}
			}
		}
		if ( preg_match_all( $patterns[1], $content, $matches ) ) {
			foreach ( $matches[2] as $srcset ) {
				foreach ( explode( ',', $srcset ) as $candidate ) {
					$parts = preg_split( '/\s+/', trim( $candidate ) );
					$url   = self::normalize_asset_url( isset( $parts[0] ) ? $parts[0] : '' );
					if ( $url ) {
						$urls[] = $url;
					}
				}
			}
		}
		if ( preg_match_all( $patterns[2], $content, $matches ) ) {
			foreach ( $matches[2] as $url ) {
				$url = self::normalize_asset_url( $url );
				if ( $url ) {
					$urls[] = $url;
				}
			}
		}
		if ( preg_match_all( $patterns[3], $content, $matches ) ) {
			foreach ( $matches[0] as $url ) {
				$url = self::normalize_asset_url( $url );
				if ( $url ) {
					$urls[] = $url;
				}
			}
		}
		return array_values( array_unique( $urls ) );
	}

	private static function normalize_asset_url( $url ) {
		$url = trim( html_entity_decode( (string) $url, ENT_QUOTES, 'UTF-8' ) );
		if ( '' === $url || 0 === strpos( $url, 'data:' ) || 0 === strpos( $url, 'blob:' ) ) {
			return '';
		}
		if ( 0 === strpos( $url, '//' ) ) {
			$url = ( is_ssl() ? 'https:' : 'http:' ) . $url;
		} elseif ( 0 === strpos( $url, '/' ) ) {
			$url = home_url( $url );
		} elseif ( ! wp_parse_url( $url, PHP_URL_SCHEME ) ) {
			$url = home_url( '/' . ltrim( $url, '/' ) );
		}
		return esc_url_raw( $url, array( 'http', 'https' ) );
	}

	private static function pack_asset( ZipArchive $zip, $url, $product_title, &$assets, &$packed ) {
		if ( isset( $assets[ $url ] ) ) {
			if ( ! in_array( $product_title, $assets[ $url ]['products'], true ) ) {
				$assets[ $url ]['products'][] = $product_title;
			}
			return;
		}
		$image = self::read_asset( $url );
		if ( is_wp_error( $image ) ) {
			$assets[ $url ] = array( 'file' => '', 'mime' => '', 'hash' => '', 'error' => $image->get_error_message(), 'products' => array( $product_title ) );
			return;
		}
		$type = self::detect_image_type( $image['body'], $image['mime'], $url );
		if ( is_wp_error( $type ) ) {
			$assets[ $url ] = array( 'file' => '', 'mime' => '', 'hash' => '', 'error' => $type->get_error_message(), 'products' => array( $product_title ) );
			return;
		}
		$bytes = $type['bytes'];
		$hash  = hash( 'sha256', $bytes );
		$file  = 'assets/' . $hash . '.' . $type['extension'];
		if ( empty( $packed[ $file ] ) ) {
			$zip->addFromString( $file, $bytes );
			$packed[ $file ] = true;
		}
		$assets[ $url ] = array( 'file' => $file, 'mime' => $type['mime'], 'hash' => $hash, 'products' => array( $product_title ) );
	}

	private static function read_asset( $url ) {
		$attachment_id = attachment_url_to_postid( $url );
		if ( $attachment_id ) {
			$path = get_attached_file( $attachment_id );
			if ( $path && is_readable( $path ) && filesize( $path ) <= self::MAX_IMAGE_MB * MB_IN_BYTES ) {
				$body = file_get_contents( $path );
				if ( false !== $body && '' !== $body ) {
					return array( 'body' => $body, 'mime' => (string) get_post_mime_type( $attachment_id ) );
				}
			}
		}
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => 30,
				'redirection'         => 5,
				'limit_response_size' => self::MAX_IMAGE_MB * MB_IN_BYTES,
				'user-agent'          => 'MaoTK Zibll Shop Migrator/' . self::VERSION . '; ' . home_url( '/' ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return new WP_Error( 'http_error', '图片服务器返回状态码 ' . wp_remote_retrieve_response_code( $response ) );
		}
		$body = wp_remote_retrieve_body( $response );
		if ( '' === $body ) {
			return new WP_Error( 'empty_image', '图片内容为空' );
		}
		return array(
			'body' => $body,
			'mime' => strtolower( trim( strtok( (string) wp_remote_retrieve_header( $response, 'content-type' ), ';' ) ) ),
		);
	}

	private static function detect_image_type( $bytes, $hint_mime = '', $hint_name = '' ) {
		$map  = array(
			'image/jpeg'               => 'jpg',
			'image/png'                => 'png',
			'image/gif'                => 'gif',
			'image/webp'               => 'webp',
			'image/avif'               => 'avif',
			'image/bmp'                => 'bmp',
			'image/x-ms-bmp'           => 'bmp',
			'image/vnd.microsoft.icon' => 'ico',
			'image/x-icon'             => 'ico',
		);
		$info = @getimagesizefromstring( $bytes );
		if ( is_array( $info ) && ! empty( $info['mime'] ) && isset( $map[ $info['mime'] ] ) ) {
			return array( 'mime' => $info['mime'], 'extension' => $map[ $info['mime'] ], 'bytes' => $bytes );
		}
		$trimmed = ltrim( preg_replace( '/^\xEF\xBB\xBF/', '', (string) $bytes ) );
		if ( preg_match( '/<svg(?:\s|>)/i', substr( $trimmed, 0, 8192 ) ) ) {
			$clean = self::sanitize_svg( $trimmed );
			if ( is_wp_error( $clean ) ) {
				return $clean;
			}
			return array( 'mime' => 'image/svg+xml', 'extension' => 'svg', 'bytes' => $clean );
		}
		return new WP_Error( 'unsupported_image', '无法识别图片格式（' . sanitize_text_field( $hint_mime . ' ' . wp_basename( $hint_name ) ) . '）' );
	}

	private static function sanitize_svg( $svg ) {
		if ( ! class_exists( 'DOMDocument' ) ) {
			return new WP_Error( 'svg_dom_missing', '服务器缺少 DOM 扩展，无法安全处理 SVG' );
		}
		$previous = libxml_use_internal_errors( true );
		$dom      = new DOMDocument();
		$loaded   = $dom->loadXML( $svg, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		if ( ! $loaded || ! $dom->documentElement || 'svg' !== strtolower( $dom->documentElement->localName ) ) {
			return new WP_Error( 'invalid_svg', 'SVG 文件结构无效' );
		}
		$xpath = new DOMXPath( $dom );
		foreach ( array( 'script', 'style', 'foreignobject', 'iframe', 'object', 'embed', 'audio', 'video', 'animate', 'animatetransform', 'animatemotion', 'set' ) as $element ) {
			$nodes = $xpath->query( '//*[translate(local-name(), "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")="' . $element . '"]' );
			if ( $nodes ) {
				for ( $i = $nodes->length - 1; $i >= 0; $i-- ) {
					$node = $nodes->item( $i );
					if ( $node && $node->parentNode ) {
						$node->parentNode->removeChild( $node );
					}
				}
			}
		}
		$nodes = $xpath->query( '//*' );
		if ( $nodes ) {
			foreach ( $nodes as $node ) {
				for ( $i = $node->attributes->length - 1; $i >= 0; $i-- ) {
					$attribute = $node->attributes->item( $i );
					$name      = strtolower( $attribute->name );
					$value     = trim( html_entity_decode( $attribute->value, ENT_QUOTES, 'UTF-8' ) );
					$is_url    = in_array( $name, array( 'href', 'xlink:href', 'src' ), true );
					$is_safe   = 0 === strpos( $value, '#' ) || (bool) preg_match( '/^data:image\/(?:png|jpeg|gif|webp|avif|bmp);base64,/i', $value );
					if (
						0 === strpos( $name, 'on' ) ||
						( $is_url && ! $is_safe ) ||
						( 'style' === $name && preg_match( '/(?:expression\s*\(|javascript\s*:|url\s*\(\s*["\']?\s*(?:javascript|data:text\/html))/i', $value ) )
					) {
						$node->removeAttributeNode( $attribute );
					}
				}
			}
		}
		$clean = $dom->saveXML( $dom->documentElement );
		return $clean ? $clean : new WP_Error( 'svg_save_failed', '无法保存清理后的 SVG' );
	}

	public static function import() {
		self::require_access();
		check_admin_referer( 'maotk_zsm_import' );
		self::prepare_long_task();
		if ( ! post_type_exists( self::POST_TYPE ) ) {
			wp_die( '当前网站没有注册 shop_product 商品类型。请先启用兼容的 Zibll 商城环境。' );
		}
		if ( ! class_exists( 'ZipArchive' ) ) {
			wp_die( '服务器未启用 PHP ZipArchive 扩展。' );
		}
		if (
			empty( $_FILES['migration_package']['tmp_name'] ) ||
			UPLOAD_ERR_OK !== (int) $_FILES['migration_package']['error'] ||
			! is_uploaded_file( $_FILES['migration_package']['tmp_name'] )
		) {
			wp_die( '迁移包上传失败。' );
		}
		if ( (int) $_FILES['migration_package']['size'] > self::MAX_PACKAGE_MB * MB_IN_BYTES ) {
			wp_die( '迁移包不能超过 ' . self::MAX_PACKAGE_MB . 'MB。' );
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$zip = new ZipArchive();
		if ( true !== $zip->open( $_FILES['migration_package']['tmp_name'] ) ) {
			wp_die( '无法打开 ZIP 迁移包。' );
		}
		if ( $zip->numFiles > self::MAX_PACKAGE_FILES ) {
			$zip->close();
			wp_die( '迁移包内文件数量异常。' );
		}
		$stat = $zip->statName( 'manifest.json' );
		if ( ! $stat || (int) $stat['size'] > self::MAX_MANIFEST_MB * MB_IN_BYTES ) {
			$zip->close();
			wp_die( '迁移清单不存在或体积异常。' );
		}
		$manifest = json_decode( (string) $zip->getFromName( 'manifest.json' ), true );
		if (
			! is_array( $manifest ) ||
			'maotk-zibll-shop-migrator' !== ( isset( $manifest['format'] ) ? $manifest['format'] : '' ) ||
			! isset( $manifest['products'], $manifest['assets'] ) ||
			! is_array( $manifest['products'] ) ||
			! is_array( $manifest['assets'] )
		) {
			$zip->close();
			wp_die( '这不是有效的 Zibll 商品迁移包。' );
		}

		$result = array(
			'created'         => 0,
			'updated'         => 0,
			'skipped'         => 0,
			'images'          => 0,
			'failed_products' => array(),
			'failed_images'   => array(),
			'warnings'        => array(),
		);
		$fields          = isset( $manifest['fields'] ) && is_array( $manifest['fields'] ) ? $manifest['fields'] : array( 'meta', 'terms', 'media' );
		$update_existing = ! empty( $_POST['update_existing'] );
		$preserve_status = ! empty( $_POST['preserve_status'] );
		$source_url      = isset( $manifest['source_url'] ) ? esc_url_raw( $manifest['source_url'] ) : '';
		$progress_token  = isset( $_POST['progress_token'] ) ? sanitize_key( wp_unslash( $_POST['progress_token'] ) ) : '';
		$asset_count     = in_array( 'media', $fields, true ) ? count( $manifest['assets'] ) : 0;
		$progress_total  = max( 1, $asset_count + count( $manifest['products'] ) );
		$progress_done   = 0;
		self::set_progress( $progress_token, 0, $progress_total, '正在准备导入' );
		$asset_map       = in_array( 'media', $fields, true ) ? self::import_assets( $zip, $manifest['assets'], $result, $progress_token, $progress_done, $progress_total ) : array();
		$used_assets     = array();

		foreach ( $manifest['products'] as $index => $item ) {
			$current_title = is_array( $item ) && isset( $item['basic'] ) && is_array( $item['basic'] ) && ! empty( $item['basic']['post_title'] ) ? sanitize_text_field( $item['basic']['post_title'] ) : '第 ' . ( $index + 1 ) . ' 个商品';
			self::set_progress( $progress_token, $progress_done, $progress_total, '正在导入商品、Meta 和分类', $current_title );
			if ( ! is_array( $item ) || empty( $item['basic'] ) || ! is_array( $item['basic'] ) ) {
				$result['failed_products'][] = array( 'title' => '第 ' . ( $index + 1 ) . ' 个商品', 'reason' => '商品数据格式无效' );
				++$progress_done;
				self::set_progress( $progress_token, $progress_done, $progress_total, '正在导入商品', $current_title );
				continue;
			}
			$basic     = $item['basic'];
			$title     = sanitize_text_field( isset( $basic['post_title'] ) ? $basic['post_title'] : '' );
			$slug      = sanitize_title( isset( $basic['post_name'] ) ? $basic['post_name'] : '' );
			$source_id = isset( $item['source_id'] ) ? (int) $item['source_id'] : 0;
			if ( '' === $title ) {
				$result['failed_products'][] = array( 'title' => '第 ' . ( $index + 1 ) . ' 个商品', 'reason' => '缺少商品标题' );
				++$progress_done;
				self::set_progress( $progress_token, $progress_done, $progress_total, '正在导入商品', $current_title );
				continue;
			}

			$existing_id = self::find_existing_product( $source_url, $source_id, $slug );
			if ( $existing_id && ! $update_existing ) {
				++$result['skipped'];
				++$progress_done;
				self::set_progress( $progress_token, $progress_done, $progress_total, '正在导入商品', $title );
				continue;
			}

			$content = isset( $basic['post_content'] ) ? (string) $basic['post_content'] : '';
			$content = self::replace_asset_urls_in_string( $content, $asset_map );
			$status  = $preserve_status ? self::safe_post_status( isset( $basic['post_status'] ) ? $basic['post_status'] : 'draft' ) : 'draft';
			$data    = array(
				'post_type'      => self::POST_TYPE,
				'post_title'     => $title,
				'post_name'      => $slug,
				'post_content'   => $content,
				'post_excerpt'   => isset( $basic['post_excerpt'] ) ? (string) $basic['post_excerpt'] : '',
				'post_status'    => $status,
				'post_date'      => self::safe_mysql_date( isset( $basic['post_date'] ) ? $basic['post_date'] : '' ),
				'post_date_gmt'  => self::safe_mysql_date( isset( $basic['post_date_gmt'] ) ? $basic['post_date_gmt'] : '' ),
				'post_modified'  => self::safe_mysql_date( isset( $basic['post_modified'] ) ? $basic['post_modified'] : '' ),
				'comment_status' => in_array( isset( $basic['comment_status'] ) ? $basic['comment_status'] : '', array( 'open', 'closed' ), true ) ? $basic['comment_status'] : 'closed',
				'ping_status'    => in_array( isset( $basic['ping_status'] ) ? $basic['ping_status'] : '', array( 'open', 'closed' ), true ) ? $basic['ping_status'] : 'closed',
				'post_password'  => sanitize_text_field( isset( $basic['post_password'] ) ? $basic['post_password'] : '' ),
				'menu_order'     => isset( $basic['menu_order'] ) ? (int) $basic['menu_order'] : 0,
				'post_author'    => get_current_user_id(),
			);
			if ( $existing_id ) {
				$data['ID'] = $existing_id;
				$post_id    = wp_update_post( wp_slash( $data ), true );
			} else {
				$post_id = wp_insert_post( wp_slash( $data ), true );
			}
			if ( is_wp_error( $post_id ) || ! $post_id ) {
				$result['failed_products'][] = array(
					'title'  => $title,
					'reason' => is_wp_error( $post_id ) ? $post_id->get_error_message() : '数据库未返回商品 ID',
				);
				++$progress_done;
				self::set_progress( $progress_token, $progress_done, $progress_total, '正在导入商品', $title );
				continue;
			}
			update_post_meta( $post_id, self::SOURCE_META, self::source_key( $source_url, $source_id ) );

			$id_map = array();
			foreach ( isset( $item['attachment_map'] ) ? (array) $item['attachment_map'] : array() as $old_id => $old_url ) {
				if ( isset( $asset_map[ $old_url ]['attachment_id'] ) ) {
					$id_map[ (int) $old_id ] = (int) $asset_map[ $old_url ]['attachment_id'];
				}
			}
			if ( in_array( 'meta', $fields, true ) ) {
				self::restore_meta( $post_id, isset( $item['meta'] ) ? $item['meta'] : array(), $asset_map, $id_map, $title, $result );
			}
			if ( in_array( 'terms', $fields, true ) ) {
				self::restore_terms( $post_id, isset( $item['terms'] ) ? $item['terms'] : array(), $title, $result );
			}
			if ( in_array( 'media', $fields, true ) ) {
				$featured = isset( $item['featured_image'] ) ? (string) $item['featured_image'] : '';
				if ( $featured && ! empty( $asset_map[ $featured ]['attachment_id'] ) ) {
					set_post_thumbnail( $post_id, (int) $asset_map[ $featured ]['attachment_id'] );
				} elseif ( $existing_id && '' === $featured ) {
					delete_post_thumbnail( $post_id );
				}
			}
			foreach ( isset( $item['asset_urls'] ) ? (array) $item['asset_urls'] : array() as $asset_url ) {
				$used_assets[ $asset_url ] = true;
			}
			if ( $existing_id ) {
				++$result['updated'];
			} else {
				++$result['created'];
			}
			++$progress_done;
			self::set_progress( $progress_token, $progress_done, $progress_total, '正在导入商品', $title );
		}

		self::cleanup_unused_assets( $asset_map, $used_assets );
		$zip->close();
		self::set_progress( $progress_token, $progress_total, $progress_total, '导入完成', '', 'finished' );
		set_transient( 'maotk_zsm_result_' . get_current_user_id(), $result, 20 * MINUTE_IN_SECONDS );
		wp_safe_redirect( admin_url( 'tools.php?page=' . self::PAGE ) );
		exit;
	}

	private static function import_assets( ZipArchive $zip, $assets, &$result, $progress_token, &$progress_done, $progress_total ) {
		$map = array();
		foreach ( $assets as $old_url => $asset ) {
			self::set_progress( $progress_token, $progress_done, $progress_total, '正在导入商品图片', wp_basename( (string) wp_parse_url( $old_url, PHP_URL_PATH ) ) );
			$old_url = esc_url_raw( $old_url, array( 'http', 'https' ) );
			if ( ! $old_url || ! is_array( $asset ) || empty( $asset['file'] ) ) {
				if ( $old_url && ! empty( $asset['error'] ) ) {
					$products = ! empty( $asset['products'] ) && is_array( $asset['products'] ) ? implode( '、', array_map( 'sanitize_text_field', $asset['products'] ) ) : '迁移包';
					$result['failed_images'][] = array( 'product' => $products, 'url' => $old_url, 'reason' => '旧站导出时未能打包：' . sanitize_text_field( $asset['error'] ) );
				}
				++$progress_done;
				self::set_progress( $progress_token, $progress_done, $progress_total, '正在导入商品图片', wp_basename( (string) wp_parse_url( $old_url, PHP_URL_PATH ) ) );
				continue;
			}
			$imported = self::import_one_asset( $zip, $asset, $old_url );
			if ( is_wp_error( $imported ) ) {
				$products = ! empty( $asset['products'] ) && is_array( $asset['products'] ) ? implode( '、', array_map( 'sanitize_text_field', $asset['products'] ) ) : '迁移包';
				$result['failed_images'][] = array( 'product' => $products, 'url' => $old_url, 'reason' => $imported->get_error_message() );
				++$progress_done;
				self::set_progress( $progress_token, $progress_done, $progress_total, '正在导入商品图片', wp_basename( (string) wp_parse_url( $old_url, PHP_URL_PATH ) ) );
				continue;
			}
			$map[ $old_url ] = $imported;
			if ( ! empty( $imported['created'] ) ) {
				++$result['images'];
			}
			++$progress_done;
			self::set_progress( $progress_token, $progress_done, $progress_total, '正在导入商品图片', wp_basename( (string) wp_parse_url( $old_url, PHP_URL_PATH ) ) );
		}
		return $map;
	}

	private static function cleanup_unused_assets( $asset_map, $used_assets ) {
		foreach ( $asset_map as $old_url => $asset ) {
			if ( ! empty( $asset['created'] ) && empty( $used_assets[ $old_url ] ) && ! empty( $asset['attachment_id'] ) ) {
				wp_delete_attachment( (int) $asset['attachment_id'], true );
			}
		}
	}

	private static function import_one_asset( ZipArchive $zip, $asset, $old_url ) {
		$file = ltrim( str_replace( '\\', '/', (string) $asset['file'] ), '/' );
		if ( 0 !== strpos( $file, 'assets/' ) || false !== strpos( $file, '../' ) || false !== strpos( $file, "\0" ) ) {
			return new WP_Error( 'unsafe_path', '文件路径不安全' );
		}
		$stat = $zip->statName( $file );
		if ( ! $stat || (int) $stat['size'] <= 0 || (int) $stat['size'] > self::MAX_IMAGE_MB * MB_IN_BYTES ) {
			return new WP_Error( 'invalid_size', '文件不存在、为空或超过 ' . self::MAX_IMAGE_MB . 'MB' );
		}
		$bytes = $zip->getFromName( $file );
		if ( false === $bytes ) {
			return new WP_Error( 'read_failed', '无法读取文件' );
		}
		$type = self::detect_image_type( $bytes, isset( $asset['mime'] ) ? $asset['mime'] : '', $file );
		if ( is_wp_error( $type ) ) {
			return $type;
		}
		$bytes = $type['bytes'];
		$hash  = hash( 'sha256', $bytes );
		$ids   = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'fields'         => 'ids',
				'posts_per_page' => 1,
				'meta_key'       => self::IMAGE_HASH_META,
				'meta_value'     => $hash,
				'no_found_rows'  => true,
			)
		);
		if ( $ids ) {
			$url = wp_get_attachment_url( $ids[0] );
			if ( $url ) {
				return array( 'url' => $url, 'attachment_id' => (int) $ids[0], 'created' => false );
			}
		}
		$base     = sanitize_file_name( pathinfo( (string) wp_parse_url( $old_url, PHP_URL_PATH ), PATHINFO_FILENAME ) );
		$base     = $base ? $base : 'product-image';
		$filename = $base . '-' . substr( $hash, 0, 8 ) . '.' . $type['extension'];
		$upload   = wp_upload_bits( $filename, null, $bytes );
		if ( ! empty( $upload['error'] ) ) {
			return new WP_Error( 'upload_failed', $upload['error'] );
		}
		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => $type['mime'],
				'post_title'     => sanitize_text_field( $base ),
				'post_status'    => 'inherit',
			),
			$upload['file']
		);
		if ( is_wp_error( $attachment_id ) || ! $attachment_id ) {
			@unlink( $upload['file'] );
			return is_wp_error( $attachment_id ) ? $attachment_id : new WP_Error( 'attachment_failed', '无法创建媒体库记录' );
		}
		update_post_meta( $attachment_id, self::IMAGE_HASH_META, $hash );
		update_post_meta( $attachment_id, self::MANAGED_IMAGE_META, '1' );
		if ( 'image/svg+xml' !== $type['mime'] ) {
			$metadata = wp_generate_attachment_metadata( $attachment_id, $upload['file'] );
			if ( ! is_wp_error( $metadata ) && $metadata ) {
				wp_update_attachment_metadata( $attachment_id, $metadata );
			}
		}
		return array( 'url' => $upload['url'], 'attachment_id' => (int) $attachment_id, 'created' => true );
	}

	private static function restore_meta( $post_id, $meta, $asset_map, $id_map, $title, &$result ) {
		if ( ! is_array( $meta ) ) {
			$result['warnings'][] = $title . '：Meta 数据格式无效。';
			return;
		}
		foreach ( $meta as $key => $values ) {
			if ( ! is_string( $key ) || in_array( $key, self::excluded_meta_keys(), true ) || ! is_array( $values ) ) {
				continue;
			}
			delete_post_meta( $post_id, $key );
			foreach ( $values as $raw_value ) {
				$value = self::safe_decode_meta( $raw_value );
				if ( is_wp_error( $value ) ) {
					$result['warnings'][] = $title . '：Meta“' . $key . '”包含不安全对象，已跳过该值。';
					continue;
				}
				$value = self::replace_references( $value, $asset_map, $id_map );
				add_post_meta( $post_id, $key, $value );
			}
		}
	}

	private static function safe_decode_meta( $raw_value ) {
		if ( ! is_string( $raw_value ) || ! is_serialized( $raw_value ) ) {
			return $raw_value;
		}
		$value = @unserialize( trim( $raw_value ), array( 'allowed_classes' => false ) );
		if ( false === $value && 'b:0;' !== trim( $raw_value ) ) {
			return new WP_Error( 'invalid_serialized_meta', '序列化数据无效' );
		}
		if ( self::contains_object( $value ) ) {
			return new WP_Error( 'unsafe_serialized_object', '不允许导入序列化对象' );
		}
		return $value;
	}

	private static function contains_object( $value ) {
		if ( is_object( $value ) ) {
			return true;
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $child ) {
				if ( self::contains_object( $child ) ) {
					return true;
				}
			}
		}
		return false;
	}

	private static function replace_references( $value, $asset_map, $id_map ) {
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $child ) {
				$value[ $key ] = self::replace_references( $child, $asset_map, $id_map );
			}
			return $value;
		}
		if ( is_int( $value ) && isset( $id_map[ $value ] ) ) {
			return $id_map[ $value ];
		}
		if ( is_string( $value ) ) {
			if ( ctype_digit( $value ) && isset( $id_map[ (int) $value ] ) ) {
				return (string) $id_map[ (int) $value ];
			}
			return self::replace_asset_urls_in_string( $value, $asset_map );
		}
		return $value;
	}

	private static function replace_asset_urls_in_string( $text, $asset_map ) {
		foreach ( $asset_map as $old_url => $asset ) {
			if ( ! empty( $asset['url'] ) ) {
				$text = str_replace(
					array( $old_url, esc_attr( $old_url ), esc_url( $old_url ) ),
					$asset['url'],
					$text
				);
			}
		}
		return $text;
	}

	private static function restore_terms( $post_id, $terms_by_taxonomy, $title, &$result ) {
		if ( ! is_array( $terms_by_taxonomy ) ) {
			return;
		}
		foreach ( $terms_by_taxonomy as $taxonomy => $terms ) {
			if ( ! taxonomy_exists( $taxonomy ) ) {
				$result['warnings'][] = $title . '：新站不存在分类法“' . sanitize_text_field( $taxonomy ) . '”。';
				continue;
			}
			$slug_to_id = array();
			$assigned   = array();
			foreach ( (array) $terms as $term_data ) {
				if ( ! is_array( $term_data ) || empty( $term_data['name'] ) ) {
					continue;
				}
				$name        = sanitize_text_field( $term_data['name'] );
				$slug        = sanitize_title( isset( $term_data['slug'] ) ? $term_data['slug'] : $name );
				$parent_slug = sanitize_title( isset( $term_data['parent_slug'] ) ? $term_data['parent_slug'] : '' );
				$parent_id   = $parent_slug && isset( $slug_to_id[ $parent_slug ] ) ? $slug_to_id[ $parent_slug ] : 0;
				$term        = term_exists( $slug, $taxonomy );
				if ( ! $term ) {
					$term = wp_insert_term(
						$name,
						$taxonomy,
						array(
							'slug'        => $slug,
							'description' => sanitize_textarea_field( isset( $term_data['description'] ) ? $term_data['description'] : '' ),
							'parent'      => $parent_id,
						)
					);
				}
				if ( is_wp_error( $term ) ) {
					$result['warnings'][] = $title . '：无法创建分类“' . $name . '”（' . $term->get_error_message() . '）。';
					continue;
				}
				$term_id             = (int) ( is_array( $term ) ? $term['term_id'] : $term );
				$slug_to_id[ $slug ] = $term_id;
				if ( ! empty( $term_data['assigned'] ) ) {
					$assigned[] = $term_id;
				}
			}
			$set = wp_set_object_terms( $post_id, array_values( array_unique( $assigned ) ), $taxonomy, false );
			if ( is_wp_error( $set ) ) {
				$result['warnings'][] = $title . '：分类法“' . $taxonomy . '”关联失败（' . $set->get_error_message() . '）。';
			}
		}
	}

	private static function source_key( $source_url, $source_id ) {
		return hash( 'sha256', untrailingslashit( (string) $source_url ) . '|' . (int) $source_id );
	}

	private static function find_existing_product( $source_url, $source_id, $slug ) {
		if ( $source_url && $source_id ) {
			$ids = get_posts(
				array(
					'post_type'      => self::POST_TYPE,
					'post_status'    => 'any',
					'fields'         => 'ids',
					'posts_per_page' => 1,
					'meta_key'       => self::SOURCE_META,
					'meta_value'     => self::source_key( $source_url, $source_id ),
					'no_found_rows'  => true,
				)
			);
			if ( $ids ) {
				return (int) $ids[0];
			}
		}
		if ( $slug ) {
			$post = get_page_by_path( $slug, OBJECT, self::POST_TYPE );
			if ( $post ) {
				return (int) $post->ID;
			}
		}
		return 0;
	}

	private static function safe_post_status( $status ) {
		return in_array( $status, array( 'publish', 'future', 'draft', 'pending', 'private' ), true ) ? $status : 'draft';
	}

	private static function safe_mysql_date( $date ) {
		return preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) $date ) ? $date : current_time( 'mysql' );
	}

	private static function prepare_long_task() {
		wp_raise_memory_limit( 'admin' );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 );
		}
	}
}

MaoTK_Zibll_Shop_Migrator::init();

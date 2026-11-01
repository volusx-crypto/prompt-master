#!/usr/bin/env node
/**
 * WordPress + Google Search Console Unified MCP Server (Zero-Dependency Node 18+)
 *
 * Connects any MCP-compatible AI Agent (Claude Desktop, Claude Code, Cursor, Windsurf, Cline)
 * directly to both WordPress plugins:
 *   1. AI Site Commander (/wp-json/ai-site-commander/v1/*)
 *   2. AI Search Console Autopilot (/wp-json/ai-gsc-autopilot/v1/*)
 */

import readline from 'node:readline';

const SITE_URL = (process.env.WP_SITE_URL || '').replace(/\/+$/, '');
const API_KEY = (process.env.ASC_API_KEY || '').trim();

const ASC_BASE = `${SITE_URL}/wp-json/ai-site-commander/v1`;
const AGSC_BASE = `${SITE_URL}/wp-json/ai-gsc-autopilot/v1`;

async function callWpApi(baseUrl, path, method = 'GET', body = null) {
	if (!SITE_URL || !API_KEY) {
		throw new Error('متغیرهای محیطی WP_SITE_URL و ASC_API_KEY در تنظیمات MCP مقداردهی نشده‌اند.');
	}

	const url = `${baseUrl}${path}`;
	const headers = {
		'Accept': 'application/json',
		'X-ASC-API-Key': API_KEY,
		'Authorization': `Bearer ${API_KEY}`,
	};

	const options = { method, headers };
	if (body !== null && method !== 'GET') {
		headers['Content-Type'] = 'application/json';
		options.body = JSON.stringify(body);
	}

	const res = await fetch(url, options);
	const text = await res.text();
	let data;
	try {
		data = text ? JSON.parse(text) : {};
	} catch {
		data = { raw: text };
	}

	if (!res.ok) {
		const msg = data?.message || `HTTP ${res.status}`;
		throw new Error(`خطای API وردپرس (${res.status}): ${msg}`);
	}
	return data;
}

const TOOLS = [
	{
		name: 'wp_get_site_status',
		description: 'دریافت وضعیت کامل سایت وردپرس، قالب فعال، افزونه سئو، ووکامرس، آمار محتوا و وضعیت پل سرچ کنسول.',
		inputSchema: { type: 'object', properties: {} },
	},
	{
		name: 'wp_list_posts',
		description: 'جستجو و دریافت لیست نوشته‌ها، برگه‌ها یا محصولات به همراه خلاصه وضعیت سئو.',
		inputSchema: {
			type: 'object',
			properties: {
				post_type: { type: 'string', description: 'post | page | product | any' },
				search: { type: 'string', description: 'عبارت جستجو (اختیاری)' },
				per_page: { type: 'number', description: 'تعداد در هر صفحه (پیش‌فرض 20)' },
				page: { type: 'number', description: 'شماره صفحه' },
			},
		},
	},
	{
		name: 'wp_get_post',
		description: 'دریافت کامل محتوا، هدینگ‌ها، دسته‌ها و متادیتای سئوی یک نوشته یا برگه با شناسه (post_id) یا آدرس (url).',
		inputSchema: {
			type: 'object',
			properties: {
				post_id: { type: 'number' },
				url: { type: 'string' },
			},
		},
	},
	{
		name: 'wp_save_post',
		description: 'ایجاد یا ویرایش مستقیم نوشته/برگه در وردپرس (همراه با Snapshot پشتیبان خودکار و امکان جستجو-جایگزینی در متن).',
		inputSchema: {
			type: 'object',
			properties: {
				id: { type: 'number', description: 'شناسه نوشته برای ویرایش (برای ایجاد جدید خالی بگذارید)' },
				type: { type: 'string', description: 'post | page' },
				status: { type: 'string', description: 'publish | draft' },
				title: { type: 'string' },
				content: { type: 'string', description: 'محتوای کامل HTML (اختیاری)' },
				append_html: { type: 'string', description: 'افزودن بخش HTML به انتهای نوشته فعلی (اختیاری)' },
				search_replace: {
					type: 'array',
					items: {
						type: 'object',
						properties: {
							search: { type: 'string' },
							replace: { type: 'string' },
						},
						required: ['search', 'replace'],
					},
				},
				seo: { type: 'object', description: 'متادیتای سئو همزمان (اختیاری)' },
				reason: { type: 'string', description: 'دلیل تغییر برای ثبت در لاگ و پشتیبان' },
			},
		},
	},
	{
		name: 'wp_update_seo',
		description: 'ویرایش مستقیم سئوی صفحه (سازگار با Rank Math، Yoast، AIOSEO و بومی) شامل تایتل، دیسکریپشن، کلمه کلیدی، کنونیکال، noindex و اسکیما JSON-LD.',
		inputSchema: {
			type: 'object',
			properties: {
				post_id: { type: 'number' },
				url: { type: 'string' },
				meta_title: { type: 'string' },
				meta_desc: { type: 'string' },
				focus_keyword: { type: 'string' },
				canonical_url: { type: 'string' },
				noindex: { type: 'boolean' },
				schema_jsonld: { type: 'object' },
				reason: { type: 'string' },
			},
		},
	},
	{
		name: 'wp_bulk_seo_audit',
		description: 'اجرای ممیزی کامل سئوی فنی روی صفحات منتشرشده سایت (شناسایی صفحات فاقد متا، محتوای کوتاه، تگ noindex و هدینگ‌ها).',
		inputSchema: {
			type: 'object',
			properties: {
				limit: { type: 'number', description: 'حداکثر صفحات بررسی‌شونده (پیش‌فرض 100)' },
			},
		},
	},
	{
		name: 'wp_inject_internal_link',
		description: 'تزریق لینک داخلی متنی از یک نوشته مبدا به آدرس مقصد همراه با پشتیبان‌گیری خودکار.',
		inputSchema: {
			type: 'object',
			properties: {
				source_post_id: { type: 'number' },
				source_url: { type: 'string' },
				anchor_text: { type: 'string' },
				target_url: { type: 'string' },
			},
			required: ['anchor_text', 'target_url'],
		},
	},
	{
		name: 'wp_fix_missing_image_alts',
		description: 'دریافت لیست تصاویر بدون متن جایگزین (Alt) یا ثبت دسته‌جمعی Alt برای تصاویر سایت.',
		inputSchema: {
			type: 'object',
			properties: {
				mode: { type: 'string', enum: ['list', 'update'] },
				updates: {
					type: 'array',
					items: {
						type: 'object',
						properties: {
							id: { type: 'number' },
							alt_text: { type: 'string' },
						},
						required: ['id', 'alt_text'],
					},
				},
			},
			required: ['mode'],
		},
	},
	{
		name: 'wp_manage_redirects_and_404',
		description: 'مدیریت کامل ریدایرکت‌های 301/302/410 و مشاهده خطاهای 404 سایت.',
		inputSchema: {
			type: 'object',
			properties: {
				mode: { type: 'string', enum: ['list_redirects', 'list_404', 'save_redirect', 'delete_redirect'] },
				source_path: { type: 'string' },
				target_url: { type: 'string' },
				status_code: { type: 'number', description: '301 | 302 | 410' },
				id: { type: 'number' },
				note: { type: 'string' },
			},
			required: ['mode'],
		},
	},
	{
		name: 'wp_manage_woocommerce',
		description: 'دریافت لیست محصولات ووکامرس یا ویرایش مستقیم قیمت، قیمت فروش ویژه، موجودی، SKU و توضیحات محصول.',
		inputSchema: {
			type: 'object',
			properties: {
				mode: { type: 'string', enum: ['list', 'update'] },
				product_id: { type: 'number' },
				name: { type: 'string' },
				regular_price: { type: 'string' },
				sale_price: { type: 'string' },
				stock_status: { type: 'string', description: 'instock | outofstock' },
				stock_quantity: { type: 'number' },
				short_description: { type: 'string' },
				description: { type: 'string' },
			},
			required: ['mode'],
		},
	},
	{
		name: 'wp_manage_snippets_and_options',
		description: 'افزودن کدهای سفارشی CSS/JS/PHP (با محافظ خطای خودکار) یا خواندن و ویرایش تنظیمات وردپرس.',
		inputSchema: {
			type: 'object',
			properties: {
				mode: { type: 'string', enum: ['list_snippets', 'save_snippet', 'get_option', 'update_option'] },
				title: { type: 'string' },
				snippet_type: { type: 'string', description: 'css | js | php | html_head | html_footer' },
				code: { type: 'string' },
				option_name: { type: 'string' },
				value: {},
			},
			required: ['mode'],
		},
	},
	{
		name: 'wp_manage_theme_files',
		description: 'لیست کردن، خواندن یا ویرایش فایل‌های قالب فعال وردپرس همراه با پشتیبان‌گیری کامل فایل قبل از تغییر.',
		inputSchema: {
			type: 'object',
			properties: {
				mode: { type: 'string', enum: ['list', 'read', 'write'] },
				relative_path: { type: 'string', description: 'مثلاً style.css یا functions.php' },
				content: { type: 'string' },
				reason: { type: 'string' },
			},
			required: ['mode'],
		},
	},
	{
		name: 'wp_snapshots_rollback',
		description: 'مشاهده لیست پشتیبان‌های خودکار (Snapshots) یا بازگردانی یک‌کلیکی تغییر قبلی به حالت اولیه.',
		inputSchema: {
			type: 'object',
			properties: {
				mode: { type: 'string', enum: ['list', 'rollback'] },
				snapshot_id: { type: 'number' },
			},
			required: ['mode'],
		},
	},
	{
		name: 'gsc_get_status_and_analytics',
		description: 'دریافت وضعیت اتصال سرچ کنسول، خلاصه کلیک/ایمپرشن ۲۸ روزه و لیست صفحات و کوئری‌های برتر گوگل.',
		inputSchema: {
			type: 'object',
			properties: {
				analytics_type: { type: 'string', enum: ['summary_only', 'page', 'page_query'] },
				limit: { type: 'number' },
			},
		},
	},
	{
		name: 'gsc_sync_now',
		description: 'اجرای همگام‌سازی زنده با Google Search Console، بررسی URLها و استخراج خطاها و فرصت‌های سئو.',
		inputSchema: { type: 'object', properties: {} },
	},
	{
		name: 'gsc_list_issues',
		description: 'دریافت لیست تمام مشکلات و فرصت‌های شناسایی‌شده سرچ کنسول (۴۰۴، noindex، CTR پایین، افت ترافیک، کنیبالیزیشن و غیره).',
		inputSchema: {
			type: 'object',
			properties: {
				status: { type: 'string', description: 'open | analyzed | fixed | all' },
				severity: { type: 'string', description: 'critical | high | medium | all' },
				limit: { type: 'number' },
			},
		},
	},
	{
		name: 'gsc_inspect_url',
		description: 'استعلام لحظه‌ای یک آدرس در Google URL Inspection API به همراه وضعیت سئوی محلی وردپرس.',
		inputSchema: {
			type: 'object',
			properties: {
				url: { type: 'string' },
			},
			required: ['url'],
		},
	},
	{
		name: 'gsc_analyze_and_fix_issue',
		description: 'تحلیل ریشه‌ای یک مشکل سرچ کنسول یا اجرای مستقیم اصلاح آن در سایت وردپرس (همراه با امکان ارسال برنامه اصلاح سفارشی).',
		inputSchema: {
			type: 'object',
			properties: {
				mode: { type: 'string', enum: ['analyze', 'execute_fix', 'run_autopilot_batch'] },
				issue_id: { type: 'number' },
				custom_plan: {
					type: 'object',
					description: 'برنامه اصلاح سفارشی تولیدشده توسط خود ایجنت شامل diagnosis_fa و آرایه actions (اختیاری)',
				},
				limit: { type: 'number' },
			},
			required: ['mode'],
		},
	},
	{
		name: 'wp_indexing_audit_and_remediate',
		description: 'بررسی وضعیت صفحات ایندکس‌نشده سرچ کنسول، سپرهای فنی، ۴۰۴ و 5xx و اجرای خودکار اصلاح جامع ایندکس همراه با پینگ IndexNow.',
		inputSchema: {
			type: 'object',
			properties: {
				mode: { type: 'string', enum: ['audit', 'remediate'] },
			},
			required: ['mode'],
		},
	},
];

async function executeTool(name, args = {}) {
	switch (name) {
		case 'wp_get_site_status':
			return callWpApi(ASC_BASE, '/status', 'GET');

		case 'wp_list_posts':
			return callWpApi(ASC_BASE, '/execute', 'POST', { action: 'list_posts', params: args });

		case 'wp_get_post':
			return callWpApi(ASC_BASE, '/execute', 'POST', { action: 'get_post', params: args });

		case 'wp_save_post':
			return callWpApi(ASC_BASE, '/execute', 'POST', {
				action: args.id ? 'update_post' : 'create_post',
				params: args,
				reason: args.reason || 'ویرایش محتوا توسط ایجنت MCP',
			});

		case 'wp_update_seo':
			return callWpApi(ASC_BASE, '/execute', 'POST', {
				action: 'update_seo',
				params: args,
				reason: args.reason || 'بهینه‌سازی سئو توسط ایجنت MCP',
			});

		case 'wp_bulk_seo_audit':
			return callWpApi(ASC_BASE, '/execute', 'POST', { action: 'bulk_seo_audit', params: args });

		case 'wp_indexing_audit_and_remediate':
			return args.mode === 'remediate'
				? callWpApi(ASC_BASE, '/indexing/remediate', 'POST', {})
				: callWpApi(ASC_BASE, '/indexing/audit', 'GET');

		case 'wp_inject_internal_link':
			return callWpApi(ASC_BASE, '/execute', 'POST', { action: 'inject_internal_link', params: args });

		case 'wp_fix_missing_image_alts':
			return args.mode === 'update'
				? callWpApi(ASC_BASE, '/execute', 'POST', { action: 'update_image_alts', params: { updates: args.updates || [] } })
				: callWpApi(ASC_BASE, '/execute', 'POST', { action: 'list_missing_alts', params: {} });

		case 'wp_manage_redirects_and_404':
			if (args.mode === 'list_404') {
				return callWpApi(ASC_BASE, '/execute', 'POST', { action: 'list_404_logs', params: { only_unresolved: true } });
			}
			if (args.mode === 'save_redirect') {
				return callWpApi(ASC_BASE, '/execute', 'POST', { action: 'save_redirect', params: args });
			}
			if (args.mode === 'delete_redirect') {
				return callWpApi(ASC_BASE, '/execute', 'POST', { action: 'delete_redirect', params: { id: args.id } });
			}
			return callWpApi(ASC_BASE, '/execute', 'POST', { action: 'list_redirects', params: {} });

		case 'wp_manage_woocommerce':
			return args.mode === 'update'
				? callWpApi(ASC_BASE, '/execute', 'POST', { action: 'update_woo_product', params: args })
				: callWpApi(ASC_BASE, '/execute', 'POST', { action: 'list_woo_products', params: args });

		case 'wp_manage_snippets_and_options':
			return callWpApi(ASC_BASE, '/execute', 'POST', { action: args.mode, params: args });

		case 'wp_manage_theme_files': {
			const map = { list: 'list_theme_files', read: 'read_theme_file', write: 'write_theme_file' };
			return callWpApi(ASC_BASE, '/execute', 'POST', {
				action: map[args.mode] || 'list_theme_files',
				params: args,
				reason: args.reason || 'ویرایش فایل قالب توسط ایجنت MCP',
			});
		}

		case 'wp_snapshots_rollback':
			return args.mode === 'rollback'
				? callWpApi(ASC_BASE, '/execute', 'POST', { action: 'rollback_snapshot', params: { snapshot_id: args.snapshot_id } })
				: callWpApi(ASC_BASE, '/execute', 'POST', { action: 'list_snapshots', params: {} });

		case 'gsc_get_status_and_analytics': {
			const status = await callWpApi(AGSC_BASE, '/status', 'GET');
			if (!args.analytics_type || args.analytics_type === 'summary_only') {
				return status;
			}
			const qs = `?type=${encodeURIComponent(args.analytics_type)}&limit=${Number(args.limit || 50)}`;
			const analytics = await callWpApi(AGSC_BASE, `/analytics${qs}`, 'GET');
			return { status, analytics };
		}

		case 'gsc_sync_now':
			return callWpApi(AGSC_BASE, '/sync', 'POST', {});

		case 'gsc_list_issues': {
			const q = new URLSearchParams();
			if (args.status) q.set('status', args.status);
			if (args.severity) q.set('severity', args.severity);
			if (args.limit) q.set('limit', String(args.limit));
			const suffix = q.toString() ? `?${q.toString()}` : '';
			return callWpApi(AGSC_BASE, `/issues${suffix}`, 'GET');
		}

		case 'gsc_inspect_url':
			return callWpApi(AGSC_BASE, '/inspect-url', 'POST', { url: args.url });

		case 'gsc_analyze_and_fix_issue':
			if (args.mode === 'run_autopilot_batch') {
				return callWpApi(AGSC_BASE, '/autopilot/run', 'POST', { limit: args.limit || 5 });
			}
			if (args.mode === 'analyze') {
				return callWpApi(AGSC_BASE, `/issues/${Number(args.issue_id)}/analyze`, 'POST', {});
			}
			return callWpApi(AGSC_BASE, `/issues/${Number(args.issue_id)}/execute-fix`, 'POST', {
				custom_plan: args.custom_plan || null,
			});

		default:
			throw new Error(`ابزار ناشناخته: ${name}`);
	}
}

function sendJsonRpc(message) {
	process.stdout.write(JSON.stringify(message) + '\n');
}

async function handleJsonRpcMessage(msg) {
	if (!msg || typeof msg !== 'object') return;

	// Notifications do not have an id and do not expect a response.
	if (msg.id === undefined || msg.id === null) {
		return;
	}

	try {
		switch (msg.method) {
			case 'initialize':
				sendJsonRpc({
					jsonrpc: '2.0',
					id: msg.id,
					result: {
						protocolVersion: '2024-11-05',
						capabilities: { tools: {} },
						serverInfo: {
							name: 'wordpress-gsc-ai-mcp',
							version: '1.1.0',
						},
					},
				});
				break;

			case 'ping':
				sendJsonRpc({ jsonrpc: '2.0', id: msg.id, result: {} });
				break;

			case 'tools/list':
				sendJsonRpc({
					jsonrpc: '2.0',
					id: msg.id,
					result: { tools: TOOLS },
				});
				break;

			case 'tools/call': {
				const toolName = msg.params?.name;
				const toolArgs = msg.params?.arguments || {};
				try {
					const output = await executeTool(toolName, toolArgs);
					sendJsonRpc({
						jsonrpc: '2.0',
						id: msg.id,
						result: {
							content: [
								{
									type: 'text',
									text: JSON.stringify(output, null, 2),
								},
							],
						},
					});
				} catch (toolErr) {
					sendJsonRpc({
						jsonrpc: '2.0',
						id: msg.id,
						result: {
							isError: true,
							content: [
								{
									type: 'text',
									text: toolErr instanceof Error ? toolErr.message : String(toolErr),
								},
							],
						},
					});
				}
				break;
			}

			default:
				sendJsonRpc({
					jsonrpc: '2.0',
					id: msg.id,
					error: {
						code: -32601,
						message: `Method not found: ${msg.method}`,
					},
				});
		}
	} catch (err) {
		sendJsonRpc({
			jsonrpc: '2.0',
			id: msg.id,
			error: {
				code: -32603,
				message: err instanceof Error ? err.message : String(err),
			},
		});
	}
}

const rl = readline.createInterface({
	input: process.stdin,
	terminal: false,
});

rl.on('line', (line) => {
	const trimmed = line.trim();
	if (!trimmed) return;
	try {
		const parsed = JSON.parse(trimmed);
		handleJsonRpcMessage(parsed);
	} catch {
		// Ignore non-JSON lines.
	}
});

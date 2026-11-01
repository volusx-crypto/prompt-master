import fs from 'node:fs';
import path from 'node:path';
import Engine from 'php-parser';

const parser = new Engine({
	parser: {
		extractDoc: true,
		php7: true,
	},
	ast: {
		withPositions: true,
	},
});

function walkPhpFiles(dir) {
	const results = [];
	for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
		const full = path.join(dir, entry.name);
		if (entry.isDirectory()) {
			results.push(...walkPhpFiles(full));
		} else if (entry.isFile() && entry.name.endsWith('.php')) {
			results.push(full);
		}
	}
	return results;
}

const pluginsDir = path.resolve('plugins');
const phpFiles = walkPhpFiles(pluginsDir);

let failed = 0;
for (const file of phpFiles) {
	const code = fs.readFileSync(file, 'utf8');
	try {
		parser.parseCode(code, path.basename(file));
		console.log(`✓ Valid PHP AST: ${path.relative(process.cwd(), file)}`);
	} catch (err) {
		failed++;
		console.error(`✗ Syntax Error in ${path.relative(process.cwd(), file)}:`, err.message);
	}
}

if (failed > 0) {
	process.exit(1);
}
console.log(`\nAll ${phpFiles.length} PHP files passed strict AST syntax validation!`);

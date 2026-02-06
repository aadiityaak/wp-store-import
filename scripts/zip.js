const fs = require("fs");
const path = require("path");
const archiver = require("archiver");

const root = process.cwd();
const pkgPath = path.join(root, "package.json");
const pkg = JSON.parse(fs.readFileSync(pkgPath, "utf8"));
const version = pkg.version || "0.0.0";
const distDir = path.join(root, "dist");
if (!fs.existsSync(distDir)) fs.mkdirSync(distDir, { recursive: true });
const outPath = path.join(distDir, `wp-store-import-${version}.zip`);

const output = fs.createWriteStream(outPath);
const archive = archiver("zip", { zlib: { level: 9 } });

output.on("close", () => {
  console.log(`Zip created: ${outPath} (${archive.pointer()} bytes)`);
});

archive.on("error", (err) => {
  console.error(err);
  process.exit(1);
});

archive.pipe(output);

archive.glob(
  "**/*",
  {
    cwd: root,
    dot: false,
    ignore: [
      "node_modules/**",
      "dist/**",
      ".git/**",
      ".trae/**",
      "scripts/**",
      ".vscode/**",
      "package.json",
      "package-lock.json",
      "bun.lockb",
      "pnpm-lock.yaml",
      "yarn.lock",
      ".editorconfig",
      ".eslint*",
      ".prettier*",
      "README.md",
      "CHANGELOG.md",
      ".DS_Store",
      "Thumbs.db",
    ],
  },
  { prefix: "wp-store-import" },
);

archive.finalize();

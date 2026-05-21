// copy-dist.js
// Copia el output de "vite build" (frontend/dist/) a la raiz del proyecto:
//   - dist/app.html        -> ../../app.html
//   - dist/assets/*        -> ../../assets/  (limpia los .js/.css viejos antes)
//
// Tambien genera ../../to-deploy.txt con la lista exacta de archivos
// frontend a subir, listo para usar con deploy.bat.
//
// No toca subcarpetas de ../../assets/ (uploads/, etc.)

const fs   = require('fs');
const path = require('path');

const distDir    = path.join(__dirname, '..', 'dist');
const distAssets = path.join(distDir, 'assets');
const rootDir    = path.join(__dirname, '..', '..');
const rootAssets = path.join(rootDir, 'assets');

if (!fs.existsSync(distDir)) {
  console.error('ERROR: no existe ' + distDir);
  console.error('Corre "npm run build" primero.');
  process.exit(1);
}

console.log('');
console.log('==========================================');
console.log(' Copiando build a la raiz del proyecto');
console.log('==========================================');

// 1. Copiar dist/app.html -> ../../app.html
fs.copyFileSync(
  path.join(distDir, 'app.html'),
  path.join(rootDir, 'app.html')
);
console.log('  OK  app.html');

// 2. Borrar .js y .css viejos de ../../assets/ (solo archivos, no carpetas)
if (!fs.existsSync(rootAssets)) {
  fs.mkdirSync(rootAssets, { recursive: true });
}
const oldFiles = fs.readdirSync(rootAssets).filter(f => {
  const full = path.join(rootAssets, f);
  return fs.statSync(full).isFile() && (f.endsWith('.js') || f.endsWith('.css'));
});
for (const f of oldFiles) {
  fs.unlinkSync(path.join(rootAssets, f));
}
console.log('  OK  ' + oldFiles.length + ' archivos viejos borrados de assets/');

// 3. Copiar dist/assets/* -> ../../assets/
const newFiles = fs.readdirSync(distAssets);
const deployList = ['app.html'];
for (const f of newFiles) {
  fs.copyFileSync(
    path.join(distAssets, f),
    path.join(rootAssets, f)
  );
  deployList.push('assets/' + f);
}
console.log('  OK  ' + newFiles.length + ' archivos copiados a assets/');

// 4. Generar to-deploy.txt en la raiz (lista lista para deploy.bat)
const deployFile = path.join(rootDir, 'to-deploy.txt');
const header = [
  '# Lista generada por "npm run build:deploy"',
  '# Estos son los archivos frontend listos para subir.',
  '# Si queres tambien subir cambios de backend (PHP),',
  '# agrega esas rutas debajo (una por linea).',
  '#',
  '# Fecha: ' + new Date().toISOString(),
  ''
].join('\n');
fs.writeFileSync(deployFile, header + deployList.join('\n') + '\n');
console.log('  OK  to-deploy.txt actualizado (' + deployList.length + ' archivos)');

console.log('');
console.log('Listo. Para subir al server:');
console.log('  doble-click en deploy.bat');
console.log('');

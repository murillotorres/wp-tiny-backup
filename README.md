# Tiny Backup

Plugin WordPress para baixar backups direto do painel, sem complicação:

- **Banco de dados** — todas as tabelas do site em `.sql` ou `.sql.gz`.
- **wp-content sem uploads** — temas, plugins, traduções etc. em `.zip`.
- **wp-content completo** — tudo, incluindo a pasta de uploads.
- **Somente uploads** — só a biblioteca de mídia, que costuma ser a parte mais pesada.

Fica em **Ferramentas → Tiny Backup**.

## Como funciona

Os arquivos são gerados enquanto o download acontece e vão direto para o navegador. Nada é gravado no servidor: não ocupa espaço em disco e não deixa backups expostos em pastas públicas. O download começa na hora, mesmo em sites grandes.

- **ZIP** gerado pelo próprio plugin (não depende da extensão `ZipArchive`). Arquivos pequenos e textos grandes (logs, SQL) são comprimidos; imagens, vídeos e outros formatos já comprimidos vão sem recompressão, o que é bem mais rápido. Suporta ZIP64 (arquivos acima de 4 GB e mais de 65 mil arquivos), testado com `unzip`, Python e o Arquivador do macOS.
- **SQL** gerado via `$wpdb` (não depende do `mysqldump`, que muitas hospedagens bloqueiam). Lê todas as tabelas numa única transação, então tabelas InnoDB saem consistentes entre si mesmo com o site no ar. Colunas binárias vão em hexadecimal, colunas geradas são puladas e cada `INSERT` fica abaixo de 1 MB, para importar sem erro de `max_allowed_packet`.
- Só exporta tabelas com o prefixo do site (ex.: `wp_`). Se o banco for compartilhado com outros sistemas, as tabelas deles ficam de fora.
- A opção **"Ignorar caches e backups feitos por outros plugins"** (ligada por padrão) deixa de fora estas pastas de `wp-content`: `cache`, `et-cache`, `upgrade`, `upgrade-temp-backup`, `updraft`, `ai1wm-backups`, `backups-dup-lite`, `backups-dup-pro`, `backuply` e `wpvividbackups`.
- Arquivos que não puderam ser lidos e links simbólicos para pastas são listados em `tiny-backup-skipped.txt`, dentro do próprio zip.
- A página mostra o tamanho de cada opção antes de baixar.

## Restaurando

- **Banco:** importe o `.sql` ou `.sql.gz` com phpMyAdmin, Adminer ou `wp db import` (para `.sql.gz`: `gunzip -c arquivo.sql.gz | wp db import -`).
- **Arquivos:** o zip contém a própria pasta `wp-content/`. Extraia na pasta do WordPress (onde fica o `wp-config.php`).

## Instalação

Copie esta pasta para `wp-content/plugins/` e ative o plugin, ou gere um zip para enviar pelo painel (Plugins → Adicionar novo → Enviar plugin):

```sh
bin/build.sh   # cria dist/tiny-backup.zip a partir do último commit
```

Requer WordPress 5.9+ e PHP 7.4+ com zlib.

## Permissões e segurança

Um backup contém o banco inteiro (inclusive os hashes de senha de todos os usuários), então só administradores podem baixar (`manage_options`; em multisite, só super admins). Os downloads exigem nonce e só funcionam com usuário logado.

## Limites conhecidos

- Algumas hospedagens matam qualquer processo PHP depois de um tempo fixo e não deixam plugins mudarem isso. A página avisa quando detecta esse caso. Se um download grande for cortado, baixe `wp-content` e uploads separadamente.
- Se o servidor tiver MySQL 8 e o destino for MariaDB, colações como `utf8mb4_0900_ai_ci` podem dar erro na importação. É o mesmo problema do `mysqldump`.
- Já existe um plugin chamado `tiny-backup` no diretório do wordpress.org. O cabeçalho `Update URI: false` impede que o WordPress ofereça "atualizar" este plugin para aquele. Para publicar no wordpress.org, seria preciso escolher outro slug.

## Filtros

```php
// Capacidade necessária para baixar backups.
add_filter( 'tiny_backup_capability', fn () => 'manage_options' );

// Tabelas incluídas no dump (nome => info). Remova entradas para pular tabelas.
add_filter( 'tiny_backup_tables', function ( $tables ) {
	unset( $tables['wp_wc_admin_logs'] );
	return $tables;
} );

// Caminhos absolutos deixados de fora dos zips ($type: content, content-full ou uploads).
add_filter( 'tiny_backup_excluded_paths', function ( $paths, $type ) {
	$paths[] = WP_CONTENT_DIR . '/themes/meu-tema/node_modules';
	return $paths;
}, 10, 2 );
```

## Traduções

Os textos do plugin estão em inglês, com tradução pt_BR em `languages/`. Depois de mudar algum texto, atualize `tiny-backup.pot` e `tiny-backup-pt_BR.po` e recompile com `msgfmt -o languages/tiny-backup-pt_BR.mo languages/tiny-backup-pt_BR.po`.

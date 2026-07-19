<#
.SYNOPSIS
    IdeaForum をロリポップへ転送する。

.DESCRIPTION
    ロリポップ!FTP(ブラウザ版)は「一度に20ファイル選択・50ファイルまで」の制限があり、
    フォルダの再帰アップロードもzip展開もできない。本アプリは vendor 込みで
    260ファイル超あるためブラウザからの投入は現実的ではなく、FTPで転送する。

    転送方式は2つ。

      WinScp (既定) : WinSCPの保存済みセッションを使う。パスワードはWinSCPが保持しており、
                      こちらで入力する必要がない。
      Ftps          : WinSCPを使わず、環境変数 IDEAFORUM_FTP_PASS のパスワードで直接FTPS転送する。

    どちらの方式でも、まず一時フォルダに「サーバーに置くべき状態そのもの」を組み立ててから
    転送する。除外ルールの適用漏れや、ローカル開発用設定の混入を防ぐため。

.EXAMPLE
    # 転送内容の確認(実際には送らない)
    .\deploy\lolipop-deploy.ps1 -WhatIf

    # WinSCPの保存セッションで転送
    .\deploy\lolipop-deploy.ps1

    # サーバー側の余分なファイルも消して完全に一致させる
    .\deploy\lolipop-deploy.ps1 -Mirror
#>
[CmdletBinding(SupportsShouldProcess = $true)]
param(
    [ValidateSet('WinScp', 'Ftps')]
    [string]$Method = 'WinScp',

    # WinSCPに保存されているセッション名
    [string]$WinScpSession = 'upper.jp-fusion@ftp-1.lolipop.jp',
    [string]$WinScpPath = 'C:\Program Files (x86)\WinSCP\WinSCP.com',

    # Ftps方式のときだけ使う
    [string]$FtpHost = 'ftp-1.lolipop.jp',
    [string]$FtpUser = 'upper.jp-fusion',

    # サーバー側の設置先(Web公開フォルダからの相対)
    [string]$RemoteDir = '/ideaforum',
    [string]$LocalRoot = (Split-Path -Parent $PSScriptRoot),

    # サーバーにあってローカルに無いファイルを削除する
    [switch]$Mirror
)

$ErrorActionPreference = 'Stop'
$LocalRoot = (Resolve-Path $LocalRoot).Path

# 転送しないもの。開発専用ファイルと、本番設定を壊すものを除外する。
$excludeDirs  = @('.git', 'deploy', 'docs', 'sql', 'node_modules')
$excludeFiles = @(
    'config/config.php',                  # ローカル開発用。本番を壊すので絶対に送らない
    'config/config.production.php',       # 中身は config/config.php として送る
    'config/config.sample.php',
    'config/config.production.sample.php',
    'composer.phar', 'composer.json', 'composer.lock',
    'README.md', '.gitignore'
)
# 開発中に溜まったメール書き出しやログは送らない(確認トークンが含まれるため)
$excludePatterns = @('^storage/mail/.+\.txt$', '^storage/logs/.+\.log$')

# Webから直接読まれては困るフォルダに置くアクセス拒否設定
$denyHtaccess = @'
<IfModule mod_authz_core.c>
    Require all denied
</IfModule>
<IfModule !mod_authz_core.c>
    Order allow,deny
    Deny from all
</IfModule>
'@

# --- 1. 転送する状態を一時フォルダに組み立てる -------------------------------
$prodConfig = Join-Path $LocalRoot 'config/config.production.php'
if (-not (Test-Path $prodConfig)) {
    throw "config/config.production.php がありません。`n" +
          "config/config.production.sample.php をコピーして作成し、DBパスワードを記入してください。"
}
if ((Get-Content $prodConfig -Raw) -match "'★") {
    throw "config/config.production.php に未記入(★)の箇所が残っています。先に埋めてください。"
}

# 組み立ては一時フォルダ内の作業なので、-WhatIf の対象から外す
# (WhatIf を伝播させると中身が作られず、確認したい転送内容が出せなくなる)
$staging = Join-Path $env:TEMP 'ideaforum-deploy'
if (Test-Path $staging) { Remove-Item $staging -Recurse -Force -WhatIf:$false }
New-Item -ItemType Directory -Path $staging -Force -WhatIf:$false | Out-Null

$sourceFiles = Get-ChildItem -Path $LocalRoot -Recurse -File -Force | Where-Object {
    $rel = $_.FullName.Substring($LocalRoot.Length + 1) -replace '\\', '/'
    $topDir = ($rel -split '/')[0]
    if ($excludeDirs -contains $topDir) { return $false }
    if ($excludeFiles -contains $rel)   { return $false }
    foreach ($pat in $excludePatterns) { if ($rel -match $pat) { return $false } }
    return $true
}

foreach ($f in $sourceFiles) {
    $rel = $f.FullName.Substring($LocalRoot.Length + 1)
    $dest = Join-Path $staging $rel
    $destDir = Split-Path $dest -Parent
    if (-not (Test-Path $destDir)) { New-Item -ItemType Directory -Path $destDir -Force -WhatIf:$false | Out-Null }
    Copy-Item $f.FullName $dest -Force -WhatIf:$false
}

# 本番設定を config/config.php として配置
Copy-Item $prodConfig (Join-Path $staging 'config/config.php') -Force -WhatIf:$false

# vendor はComposerが生成するフォルダで .htaccess を持たないため、ここで補う
Set-Content -Path (Join-Path $staging 'vendor/.htaccess') -Value $denyHtaccess -Encoding UTF8 -WhatIf:$false

$stagedFiles = Get-ChildItem $staging -Recurse -File -Force
$stagedDirs  = Get-ChildItem $staging -Recurse -Directory -Force

Write-Host "ローカル : $LocalRoot"
Write-Host "組み立て : $staging"
Write-Host "転送先   : $RemoteDir  (方式 $Method)"
Write-Host ""
Write-Host ("転送予定: {0} ファイル / {1} フォルダ" -f $stagedFiles.Count, ($stagedDirs.Count + 1))

if ($WhatIfPreference) {
    Write-Host "`n--- 転送するファイル(先頭30件) ---"
    $stagedFiles | Select-Object -First 30 | ForEach-Object {
        Write-Host ("  {0}" -f ($_.FullName.Substring($staging.Length + 1) -replace '\\', '/'))
    }
    if ($stagedFiles.Count -gt 30) { Write-Host ("  ... 他 {0} 件" -f ($stagedFiles.Count - 30)) }
    Write-Host "`n設定ファイルの確認:"
    Write-Host ("  config/config.php  : {0}" -f $(if (Test-Path (Join-Path $staging 'config/config.php')) { '本番用を配置済み' } else { '未配置' }))
    Write-Host ("  vendor/.htaccess   : {0}" -f $(if (Test-Path (Join-Path $staging 'vendor/.htaccess')) { '生成済み' } else { '未生成' }))
    Write-Host "`n-WhatIf のため実際には転送していません。"
    return
}

if (-not $PSCmdlet.ShouldProcess($RemoteDir, "$($stagedFiles.Count) ファイルを転送")) { return }

# --- 2. 転送 ----------------------------------------------------------------
if ($Method -eq 'WinScp') {
    if (-not (Test-Path $WinScpPath)) {
        throw "WinSCP.com が見つかりません: $WinScpPath`n-WinScpPath で場所を指定するか、-Method Ftps をお使いください。"
    }

    $syncArgs = if ($Mirror) { '-delete' } else { '' }
    $script = @"
option batch abort
option confirm off
open "$WinScpSession"
synchronize remote $syncArgs -criteria=either "$staging" "$RemoteDir"
exit
"@
    $scriptFile = Join-Path $env:TEMP 'ideaforum-winscp.txt'
    Set-Content -Path $scriptFile -Value $script -Encoding UTF8

    Write-Host "`nWinSCP で転送中... (保存済みセッション: $WinScpSession)"
    & $WinScpPath /script="$scriptFile" /log="$env:TEMP\ideaforum-winscp.log"
    $code = $LASTEXITCODE
    Remove-Item $scriptFile -Force -ErrorAction SilentlyContinue

    if ($code -ne 0) {
        throw "WinSCP がエラー終了しました (コード $code)。ログ: $env:TEMP\ideaforum-winscp.log"
    }
    Write-Host "`n転送が完了しました。"

} else {
    $password = $env:IDEAFORUM_FTP_PASS
    if ([string]::IsNullOrWhiteSpace($password)) {
        throw "環境変数 IDEAFORUM_FTP_PASS が未設定です。`n" +
              "パスワードが不明な場合は -Method WinScp をお使いください。"
    }
    $credential = New-Object System.Net.NetworkCredential($FtpUser, $password)

    function New-RemoteDirectory {
        param([string]$RemotePath)
        $request = [System.Net.FtpWebRequest]::Create("ftp://$FtpHost$RemotePath")
        $request.Method = [System.Net.WebRequestMethods+Ftp]::MakeDirectory
        $request.Credentials = $credential
        $request.EnableSsl = $true
        $request.UsePassive = $true
        try { $request.GetResponse().Close() }
        catch [System.Net.WebException] {
            if ($_.Exception.Response.StatusCode -ne [System.Net.FtpStatusCode]::ActionNotTakenFileUnavailable) { throw }
        }
    }

    # 祖先まで展開しないと中間フォルダが作られない
    $dirSet = [System.Collections.Generic.HashSet[string]]::new()
    foreach ($f in $stagedFiles) {
        $rel = $f.FullName.Substring($staging.Length + 1) -replace '\\', '/'
        $parts = (Split-Path $rel -Parent) -replace '\\', '/'
        if ($parts) {
            $seg = $parts -split '/'
            for ($n = 1; $n -le $seg.Count; $n++) { [void]$dirSet.Add("$RemoteDir/" + ($seg[0..($n-1)] -join '/')) }
        }
    }
    $dirs = @($RemoteDir) + ($dirSet | Sort-Object { ($_ -split '/').Count }, { $_ })

    Write-Host "`nフォルダを作成中..."
    foreach ($d in $dirs) { New-RemoteDirectory -RemotePath $d }

    Write-Host "ファイルを転送中..."
    $i = 0; $failed = @()
    foreach ($f in $stagedFiles) {
        $i++
        $rel = $f.FullName.Substring($staging.Length + 1) -replace '\\', '/'
        Write-Progress -Activity "FTPS アップロード" -Status $rel -PercentComplete (100 * $i / $stagedFiles.Count)
        try {
            $request = [System.Net.FtpWebRequest]::Create("ftp://$FtpHost$RemoteDir/$rel")
            $request.Method = [System.Net.WebRequestMethods+Ftp]::UploadFile
            $request.Credentials = $credential
            $request.EnableSsl = $true
            $request.UsePassive = $true
            $request.UseBinary = $true
            $bytes = [System.IO.File]::ReadAllBytes($f.FullName)
            $request.ContentLength = $bytes.Length
            $s = $request.GetRequestStream()
            try { $s.Write($bytes, 0, $bytes.Length) } finally { $s.Close() }
            $request.GetResponse().Close()
        } catch {
            $failed += [pscustomobject]@{ Path = $rel; Error = $_.Exception.Message }
        }
    }
    Write-Progress -Activity "FTPS アップロード" -Completed

    Write-Host ("`n完了: {0} / {1} ファイル" -f ($stagedFiles.Count - $failed.Count), $stagedFiles.Count)
    if ($failed.Count -gt 0) {
        $failed | ForEach-Object { Write-Warning ("  {0} : {1}" -f $_.Path, $_.Error) }
        exit 1
    }
}

Write-Host ""
Write-Host "次の手順:"
Write-Host "  1. https://fusion.upper.jp/ideaforum/ を開いて表示を確認"
Write-Host "  2. 会員登録する (ここで users に行ができる)"
Write-Host "  3. sql/make-admin.sql を phpMyAdmin で実行して管理者に昇格"

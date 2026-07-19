<#
.SYNOPSIS
    IdeaForum をロリポップへFTPSで再帰アップロードする。

.DESCRIPTION
    ロリポップ!FTP(ブラウザ版)は「一度に20ファイル選択・50ファイルまで」の制限があり、
    フォルダの再帰アップロードもzip展開もできない。本アプリは vendor 込みで
    300ファイル超あるためブラウザからの投入は現実的ではなく、FTPで転送する。

    パスワードは環境変数から読み取る。スクリプト内にも履歴にも残らない。

.EXAMPLE
    # 1. パスワードを環境変数に入れる(この行だけご自身で実行してください)
    $env:IDEAFORUM_FTP_PASS = 'FTPのパスワード'

    # 2. 転送内容の確認(実際には送らない)
    .\deploy\lolipop-deploy.ps1 -WhatIf

    # 3. 実行
    .\deploy\lolipop-deploy.ps1
#>
[CmdletBinding(SupportsShouldProcess = $true)]
param(
    [string]$FtpHost   = 'ftp-1.lolipop.jp',
    [string]$FtpUser   = 'upper.jp-fusion',
    # サーバー側の設置先。Web公開フォルダ直下からの相対パス。
    [string]$RemoteDir = '/ideaforum',
    # ローカルのプロジェクトルート(このスクリプトの1つ上)
    [string]$LocalRoot = (Split-Path -Parent $PSScriptRoot)
)

$ErrorActionPreference = 'Stop'

$password = $env:IDEAFORUM_FTP_PASS
if ([string]::IsNullOrWhiteSpace($password)) {
    throw "環境変数 IDEAFORUM_FTP_PASS が未設定です。`n" +
          "  `$env:IDEAFORUM_FTP_PASS = 'FTPのパスワード'`n" +
          "を実行してから、もう一度このスクリプトを実行してください。"
}

$credential = New-Object System.Net.NetworkCredential($FtpUser, $password)

# 転送しないもの。開発専用ファイルと、本番設定を上書きしてしまうものを除外する。
$excludeDirs = @('.git', 'deploy', 'docs', 'sql', 'node_modules')
$excludeFiles = @(
    'config/config.php',                  # ローカル開発用。本番を壊すので絶対に送らない
    'config/config.production.php',       # 中身は config/config.php として別途送る
    'config/config.sample.php',
    'config/config.production.sample.php',
    'composer.phar', 'composer.json', 'composer.lock',
    'README.md', '.gitignore'
)
# 開発中に溜まったメール書き出しやログは送らない(確認トークンが含まれるため)
$excludePatterns = @('^storage/mail/.+\.txt$', '^storage/logs/.+\.log$')

function Get-RemoteUri {
    param([string]$Path)
    $clean = ($Path -replace '\\', '/') -replace '/+', '/'
    return "ftp://$FtpHost$clean"
}

function New-RemoteDirectory {
    param([string]$RemotePath)
    $request = [System.Net.FtpWebRequest]::Create((Get-RemoteUri $RemotePath))
    $request.Method = [System.Net.WebRequestMethods+Ftp]::MakeDirectory
    $request.Credentials = $credential
    $request.EnableSsl = $true
    $request.UsePassive = $true
    try {
        $response = $request.GetResponse()
        $response.Close()
        Write-Verbose "mkdir $RemotePath"
    } catch [System.Net.WebException] {
        # 既に存在する場合は 550 が返る。それ以外は本物のエラー。
        $status = $_.Exception.Response.StatusCode
        if ($status -ne [System.Net.FtpStatusCode]::ActionNotTakenFileUnavailable) {
            throw "フォルダ作成に失敗: $RemotePath ($status)"
        }
    }
}

function Send-RemoteFile {
    param([string]$LocalPath, [string]$RemotePath)
    $request = [System.Net.FtpWebRequest]::Create((Get-RemoteUri $RemotePath))
    $request.Method = [System.Net.WebRequestMethods+Ftp]::UploadFile
    $request.Credentials = $credential
    $request.EnableSsl = $true
    $request.UsePassive = $true
    $request.UseBinary = $true

    $bytes = [System.IO.File]::ReadAllBytes($LocalPath)
    $request.ContentLength = $bytes.Length
    $stream = $request.GetRequestStream()
    try {
        $stream.Write($bytes, 0, $bytes.Length)
    } finally {
        $stream.Close()
    }
    $response = $request.GetResponse()
    $response.Close()
}

# --- 転送対象の収集 ---------------------------------------------------------
$LocalRoot = (Resolve-Path $LocalRoot).Path
Write-Host "ローカル : $LocalRoot"
Write-Host "転送先   : ftp://$FtpHost$RemoteDir  (ユーザー $FtpUser)"
Write-Host ""

$allFiles = Get-ChildItem -Path $LocalRoot -Recurse -File -Force | Where-Object {
    $rel = $_.FullName.Substring($LocalRoot.Length + 1) -replace '\\', '/'
    $topDir = ($rel -split '/')[0]
    if ($excludeDirs -contains $topDir) { return $false }
    if ($excludeFiles -contains $rel)   { return $false }
    foreach ($pat in $excludePatterns) { if ($rel -match $pat) { return $false } }
    return $true
}

# 本番設定は config.production.php を config/config.php という名前で送る
$prodConfig = Join-Path $LocalRoot 'config/config.production.php'
if (-not (Test-Path $prodConfig)) {
    throw "config/config.production.php がありません。`n" +
          "config/config.production.sample.php をコピーして作成し、DBパスワードを記入してください。"
}
# 値として書かれた ★プレースホルダ★ だけを見る(説明コメント内の ★ は無視)
if ((Get-Content $prodConfig -Raw) -match "'★") {
    throw "config/config.production.php に未記入(★)の箇所が残っています。先に埋めてください。"
}

$plan = @()
foreach ($f in $allFiles) {
    $rel = $f.FullName.Substring($LocalRoot.Length + 1) -replace '\\', '/'
    $plan += [pscustomobject]@{ Local = $f.FullName; Remote = "$RemoteDir/$rel" }
}
$plan += [pscustomobject]@{ Local = $prodConfig; Remote = "$RemoteDir/config/config.php" }

# 必要なリモートフォルダを浅い順に。
# ファイルの親だけでは中間フォルダ(例 vendor/slim)や $RemoteDir 自身が漏れるため、
# 各パスを祖先まで展開してから重複を除く。
$dirSet = [System.Collections.Generic.HashSet[string]]::new()
foreach ($item in $plan) {
    $parent = (Split-Path $item.Remote -Parent) -replace '\\', '/'
    $parts = $parent.Trim('/') -split '/'
    for ($n = 1; $n -le $parts.Count; $n++) {
        [void]$dirSet.Add('/' + ($parts[0..($n - 1)] -join '/'))
    }
}
$dirs = $dirSet | Sort-Object { ($_ -split '/').Count }, { $_ }

Write-Host ("転送予定: {0} ファイル / {1} フォルダ" -f $plan.Count, $dirs.Count)

if ($WhatIfPreference) {
    Write-Host "`n--- 作成するフォルダ ---"
    $dirs | ForEach-Object { Write-Host "  $_" }
    Write-Host "`n--- 転送するファイル(先頭30件) ---"
    $plan | Select-Object -First 30 | ForEach-Object { Write-Host ("  {0}" -f $_.Remote) }
    if ($plan.Count -gt 30) { Write-Host ("  ... 他 {0} 件" -f ($plan.Count - 30)) }
    Write-Host "`n-WhatIf のため実際には転送していません。"
    return
}

# --- 実行 -------------------------------------------------------------------
if (-not $PSCmdlet.ShouldProcess("ftp://$FtpHost$RemoteDir", "$($plan.Count) ファイルを転送")) {
    return
}

Write-Host "`nフォルダを作成中..."
foreach ($d in $dirs) { New-RemoteDirectory -RemotePath $d }

Write-Host "ファイルを転送中..."
$i = 0
$failed = @()
foreach ($item in $plan) {
    $i++
    Write-Progress -Activity "FTPS アップロード" -Status $item.Remote -PercentComplete (100 * $i / $plan.Count)
    try {
        Send-RemoteFile -LocalPath $item.Local -RemotePath $item.Remote
    } catch {
        $failed += [pscustomobject]@{ Remote = $item.Remote; Error = $_.Exception.Message }
    }
}
Write-Progress -Activity "FTPS アップロード" -Completed

Write-Host ""
Write-Host ("完了: {0} / {1} ファイル転送" -f ($plan.Count - $failed.Count), $plan.Count)
if ($failed.Count -gt 0) {
    Write-Warning "$($failed.Count) 件失敗しました:"
    $failed | ForEach-Object { Write-Warning ("  {0} : {1}" -f $_.Remote, $_.Error) }
    exit 1
}
Write-Host "次は phpMyAdmin で sql/schema.sql を実行してください。"

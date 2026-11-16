<?php
/*
 * Formulário de contato do site da Insolar Brasil
 * - Envia o contato (e a foto da conta de luz) para comercial@insolarbrasil.com.br
 * - Guarda uma cópia de cada contato FORA do site, na pasta /home/USUARIO/contatos-site
 *   (planilha contatos.csv + arquivos das contas), para nada se perder.
 */

date_default_timezone_set('America/Bahia');
$PARA      = 'comercial@insolarbrasil.com.br, insolarbrasil.adm@gmail.com';
$REMETENTE = 'site@insolarbrasil.com.br';
$PASTA     = dirname(__DIR__) . '/contatos-site';
$LIMITE_MB = 8;


// A hospedagem desliga a função mail() do PHP; o envio é feito direto pelo
// servidor de e-mail da própria hospedagem (localhost, porta 25).
function enviar_smtp($de, array $para, $cabecalhos, $corpo) {
    $s = @stream_socket_client('tcp://localhost:25', $en, $es, 10);
    if (!$s) return false;
    stream_set_timeout($s, 15);
    $ler = function () use ($s) { $r = ''; while (($l = fgets($s, 1000)) !== false) { $r .= $l; if (isset($l[3]) && $l[3] === ' ') break; } return $r; };
    $cmd = function ($c) use ($s, $ler) { fwrite($s, $c . "\r\n"); return $ler(); };
    $ok = function ($r, $cod) { return strncmp($r, $cod, 3) === 0; };
    if (!$ok($ler(), '220')) { fclose($s); return false; }
    $cmd('EHLO insolarbrasil.com.br');
    if (!$ok($cmd("MAIL FROM:<$de>"), '250')) { fclose($s); return false; }
    $aceitos = 0;
    foreach ($para as $p) { if ($ok($cmd('RCPT TO:<' . trim($p) . '>'), '250')) $aceitos++; }
    if (!$aceitos || !$ok($cmd('DATA'), '354')) { fclose($s); return false; }
    $msg = $cabecalhos . "\r\n" . preg_replace('/^\./m', '..', str_replace(["\r\n", "\n"], ["\n", "\r\n"], $corpo));
    $fim = $cmd($msg . "\r\n.");
    $cmd('QUIT');
    fclose($s);
    return $ok($fim, '250');
}

function voltar_erro($msg) {
    $m = rawurlencode($msg);
    header("Location: contato.html?erro=$m#formulario", true, 303);
    exit;
}
function limpo($v, $max) {
    $v = trim((string)$v);
    $v = str_replace(["\r", "\n", "\0"], ' ', $v);
    return mb_substr($v, 0, $max, 'UTF-8');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: contato.html', true, 303); exit; }

// Robôs de propaganda preenchem o campo escondido
if (!empty($_POST['site_web'])) { header('Location: obrigado.html', true, 303); exit; }

$nome     = limpo($_POST['nome'] ?? '', 80);
$telefone = limpo($_POST['telefone'] ?? '', 20);
$email    = limpo($_POST['email'] ?? '', 100);
$cidade   = limpo($_POST['cidade'] ?? '', 60);
$tipo     = limpo($_POST['tipo'] ?? '', 60);
$valor    = limpo($_POST['valor'] ?? '', 12);
$mensagem = trim(mb_substr(str_replace("\0", '', (string)($_POST['mensagem'] ?? '')), 0, 800, 'UTF-8'));
$aceite   = ($_POST['aceite'] ?? '') === 'sim';
$origem   = limpo($_POST['origem'] ?? '', 40);
if ($origem === '') $origem = 'Contato';

$digitos = preg_replace('/\D/', '', $telefone);
if ($nome === '' || $cidade === '' || $tipo === '') voltar_erro('Preencha nome, cidade e para que é o sistema.');
if (strlen($digitos) < 10 || strlen($digitos) > 13)  voltar_erro('Confira o número de WhatsApp com DDD.');
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) voltar_erro('Confira o e-mail digitado.');
if (!$aceite) voltar_erro('Marque a autorização para podermos entrar em contato.');

if (!is_dir($PASTA)) { @mkdir($PASTA, 0750, true); @file_put_contents($PASTA . '/.htaccess', "Require all denied\n"); }

// Evita envio repetido em sequência (mesmo endereço em menos de 20 segundos)
$ip = $_SERVER['REMOTE_ADDR'] ?? '';
$trava = $PASTA . '/.ultimo_' . md5($ip);
if (is_file($trava) && time() - filemtime($trava) < 20) { header('Location: obrigado.html', true, 303); exit; }
@touch($trava);

// Anexo (opcional)
$anexo = null;
if (!empty($_FILES['conta']) && $_FILES['conta']['error'] !== UPLOAD_ERR_NO_FILE) {
    $f = $_FILES['conta'];
    if ($f['error'] !== UPLOAD_ERR_OK) voltar_erro('Não conseguimos receber o arquivo. Tente uma foto menor ou mande pelo WhatsApp.');
    if ($f['size'] > $LIMITE_MB * 1024 * 1024) voltar_erro("O arquivo passou de {$LIMITE_MB} MB. Tente uma foto menor ou mande pelo WhatsApp.");
    $tipos = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/heic' => 'heic',
              'image/heif' => 'heif', 'application/pdf' => 'pdf'];
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    if (!isset($tipos[$mime])) voltar_erro('Envie a conta de luz em foto (JPG ou PNG) ou em PDF.');
    $nomeArq = date('Y-m-d_His') . '_' . preg_replace('/[^a-z0-9]+/', '-', strtolower(iconv('UTF-8', 'ASCII//TRANSLIT', $nome))) . '.' . $tipos[$mime];
    $destino = $PASTA . '/' . $nomeArq;
    if (!move_uploaded_file($f['tmp_name'], $destino)) voltar_erro('Não conseguimos guardar o arquivo. Mande a conta pelo WhatsApp.');
    $anexo = ['caminho' => $destino, 'nome' => $nomeArq, 'mime' => $mime];
}

$quando = date('d/m/Y H:i');
$wa = 'https://wa.me/55' . ltrim($digitos, '0');
if (strlen($digitos) > 11 && substr($digitos, 0, 2) === '55') $wa = 'https://wa.me/' . $digitos;

// Cópia de segurança (planilha)
$csv = $PASTA . '/contatos.csv';
$novo = !is_file($csv);
if ($fp = @fopen($csv, 'a')) {
    if ($novo) { fwrite($fp, "\xEF\xBB\xBF"); fputcsv($fp, ['Data', 'Nome', 'WhatsApp', 'E-mail', 'Cidade', 'Tipo', 'Conta (R$)', 'Mensagem', 'Arquivo da conta', 'Página do site'], ';'); }
    fputcsv($fp, [$quando, $nome, $telefone, $email, $cidade, $tipo, $valor, $mensagem, $anexo['nome'] ?? '', $origem], ';');
    fclose($fp);
}

// E-mail para o comercial
$assunto = '=?UTF-8?B?' . base64_encode("☀️ Novo contato pelo site — $nome — $cidade") . '?=';
$texto  = "Novo contato pelo site insolarbrasil.com.br\n\n";
$texto .= "Data: $quando\nNome: $nome\nWhatsApp: $telefone\nResponder no WhatsApp: $wa\n";
$texto .= "E-mail: " . ($email ?: '—') . "\nCidade: $cidade\nO sistema é para: $tipo\n";
$texto .= "Valor médio da conta: " . ($valor !== '' ? "R$ $valor" : '—') . "\n";
$texto .= "Conta de luz anexada: " . ($anexo ? 'sim' : 'não') . "\n\nMensagem:\n" . ($mensagem ?: '—') . "\n\n";
$texto .= "Origem: Site (página $origem)\n";
$texto .= "Cliente autorizou o contato. Responder em até 1 hora.\n";

$sep = 'insolar_' . md5(uniqid('', true));
$cab  = "From: =?UTF-8?B?" . base64_encode('Site Insolar Brasil') . "?= <$REMETENTE>\r\n";
if ($email !== '') $cab .= "Reply-To: $email\r\n";
$cab .= "MIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"$sep\"\r\n";

$corpo  = "--$sep\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n";
$corpo .= chunk_split(base64_encode($texto)) . "\r\n";
if ($anexo) {
    $corpo .= "--$sep\r\nContent-Type: {$anexo['mime']}; name=\"{$anexo['nome']}\"\r\n";
    $corpo .= "Content-Transfer-Encoding: base64\r\nContent-Disposition: attachment; filename=\"{$anexo['nome']}\"\r\n\r\n";
    $corpo .= chunk_split(base64_encode(file_get_contents($anexo['caminho']))) . "\r\n";
}
$corpo .= "--$sep--\r\n";

$destinos = array_map('trim', explode(',', $PARA));
$cabTudo  = "To: " . implode(', ', $destinos) . "\r\nSubject: $assunto\r\nDate: " . date('r') . "\r\nMessage-ID: <" . md5(uniqid('', true)) . "@insolarbrasil.com.br>\r\n" . $cab;
$enviado = false;
try { $enviado = enviar_smtp($REMETENTE, $destinos, $cabTudo, $corpo); } catch (Throwable $e) { $enviado = false; }
if (!$enviado) @file_put_contents($PASTA . '/falhas-de-envio.txt', "$quando - $nome - $telefone\n", FILE_APPEND);

// ---------------------------------------------------------------------------
// PIPEDRIVE (16/11/2026): o pedido entra sozinho no funil "Vendas - Energia Solar",
// etapa "Novo contato", com a origem "Site". Vem DEPOIS do e-mail e da planilha.
// A chave fica FORA do site, em /home/insola57/contatos-site/pipedrive-config.php.
// Sem chave ou com qualquer falha: o cliente não percebe nada; só anota a falha.
// ---------------------------------------------------------------------------
$PD_FUNIL = 1; $PD_ETAPA = 1; $PD_DONO = 28978787;
$PD_CAMPO_ORIGEM = '75ab974e99f659c02f1e885103996d38a1192f48'; $PD_ORIGEM_SITE = 42;

function pd_registro($pasta, $quando, $nome, $msg) {
    @file_put_contents($pasta . '/falhas-pipedrive.txt', "$quando - $nome - $msg\n", FILE_APPEND);
}
function pd_post($token, $caminho, array $dados) {
    $url = 'https://api.pipedrive.com/v1/' . $caminho;
    $json = json_encode($dados, JSON_UNESCAPED_UNICODE);
    $cab = ['Content-Type: application/json', 'Accept: application/json', 'x-api-token: ' . $token];
    if (function_exists('curl_init')) {
        $c = curl_init($url);
        curl_setopt_array($c, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $json, CURLOPT_HTTPHEADER => $cab,
            CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_TIMEOUT => 8]);
        $r = curl_exec($c); $cod = (int)curl_getinfo($c, CURLINFO_HTTP_CODE); curl_close($c);
    } else {
        $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => implode("\r\n", $cab), 'content' => $json, 'timeout' => 8, 'ignore_errors' => true]]);
        $r = @file_get_contents($url, false, $ctx); $cod = 0;
        if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) $cod = (int)$m[1];
    }
    $j = $r ? json_decode($r, true) : null;
    if ($cod < 200 || $cod >= 300 || empty($j['success'])) throw new Exception("$caminho respondeu $cod");
    return $j['data'];
}
try {
    $PD_TOKEN = '';
    $cfg = $PASTA . '/pipedrive-config.php';
    if (is_file($cfg)) { include $cfg; if (defined('PIPEDRIVE_TOKEN')) $PD_TOKEN = trim((string)PIPEDRIVE_TOKEN); }
    if ($PD_TOKEN === '') {
        pd_registro($PASTA, $quando, $nome, 'chave do Pipedrive vazia: pedido não lançado (está no e-mail e na planilha)');
    } else {
        $pessoa = ['name' => $nome, 'owner_id' => $PD_DONO, 'phone' => [['value' => $telefone, 'primary' => true, 'label' => 'mobile']]];
        if ($email !== '') $pessoa['email'] = [['value' => $email, 'primary' => true, 'label' => 'work']];
        $p = pd_post($PD_TOKEN, 'persons', $pessoa);
        $d = pd_post($PD_TOKEN, 'deals', ['title' => "Site – $nome – $cidade", 'person_id' => $p['id'], 'pipeline_id' => $PD_FUNIL,
            'stage_id' => $PD_ETAPA, 'user_id' => $PD_DONO, 'currency' => 'BRL', $PD_CAMPO_ORIGEM => $PD_ORIGEM_SITE]);
        $nota  = "Pedido pelo site insolarbrasil.com.br ($quando)<br>";
        $nota .= "Página do site: " . htmlspecialchars($origem) . "<br>";
        $nota .= "O sistema é para: " . htmlspecialchars($tipo) . "<br>";
        $nota .= "Valor médio da conta: " . ($valor !== '' ? 'R$ ' . htmlspecialchars($valor) : '—') . "<br>";
        $nota .= "Mandou a conta de luz: " . ($anexo ? 'sim (está no e-mail comercial@ e na pasta contatos-site)' : 'não') . "<br>";
        $nota .= "Cidade: " . htmlspecialchars($cidade) . "<br>WhatsApp: " . htmlspecialchars($telefone) . "<br>";
        $nota .= "Mensagem: " . ($mensagem !== '' ? nl2br(htmlspecialchars($mensagem)) : '—');
        pd_post($PD_TOKEN, 'notes', ['content' => $nota, 'deal_id' => $d['id']]);
    }
} catch (Throwable $e) {
    pd_registro($PASTA, $quando, $nome, 'Pipedrive falhou: ' . $e->getMessage());
}

$primeiro = rawurlencode(strtok($nome, ' '));
header("Location: obrigado.html?n=$primeiro", true, 303);
exit;

<?php
/*
 * Formulário de contato do site da Insolar Brasil
 * - Envia o contato (e a foto da conta de luz) para comercial@insolarbrasil.com.br
 * - Guarda uma cópia de cada contato FORA do site, na pasta /home/USUARIO/contatos-site
 *   (planilha contatos.csv + arquivos das contas), para nada se perder.
 */

date_default_timezone_set('America/Bahia');
$PARA      = 'comercial@insolarbrasil.com.br';
$REMETENTE = 'site@insolarbrasil.com.br';
$PASTA     = dirname(__DIR__) . '/contatos-site';
$LIMITE_MB = 8;

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
    if ($novo) { fwrite($fp, "\xEF\xBB\xBF"); fputcsv($fp, ['Data', 'Nome', 'WhatsApp', 'E-mail', 'Cidade', 'Tipo', 'Conta (R$)', 'Mensagem', 'Arquivo da conta'], ';'); }
    fputcsv($fp, [$quando, $nome, $telefone, $email, $cidade, $tipo, $valor, $mensagem, $anexo['nome'] ?? ''], ';');
    fclose($fp);
}

// E-mail para o comercial
$assunto = '=?UTF-8?B?' . base64_encode("☀️ Novo contato pelo site — $nome — $cidade") . '?=';
$texto  = "Novo contato pelo site insolarbrasil.com.br\n\n";
$texto .= "Data: $quando\nNome: $nome\nWhatsApp: $telefone\nResponder no WhatsApp: $wa\n";
$texto .= "E-mail: " . ($email ?: '—') . "\nCidade: $cidade\nO sistema é para: $tipo\n";
$texto .= "Valor médio da conta: " . ($valor !== '' ? "R$ $valor" : '—') . "\n";
$texto .= "Conta de luz anexada: " . ($anexo ? 'sim' : 'não') . "\n\nMensagem:\n" . ($mensagem ?: '—') . "\n\n";
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

@mail($PARA, $assunto, $corpo, $cab, '-f' . $REMETENTE);

$primeiro = rawurlencode(strtok($nome, ' '));
header("Location: obrigado.html?n=$primeiro", true, 303);
exit;

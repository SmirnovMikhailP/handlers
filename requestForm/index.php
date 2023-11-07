<?php

use Teboil\Application\Helpers\Env;


require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

$curl = curl_init();

function response($content = [], $code = 200, $headers = []): void
{
    http_response_code($code);
    foreach ($headers as $header) {
        header($header);
    }

    if (is_array($content)) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($content);
    } else {
        echo $content;
    }
    exit;
}

function post($url, $data, $headers = [])
{
    if (is_array($data)) {
        $data = http_build_query($data);
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_FAILONERROR, 1);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
    if (!empty($headers)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    $result = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    if ($status === false) {
        return false;
    }

    return $result;
}

function soapResponseToObject($contents)
{
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML($contents);
    libxml_clear_errors();
    $xml = $doc->saveXML($doc->documentElement);
    return simplexml_load_string($xml);
}

function validateRecaptchaResponse($gRecaptchaResponse): bool
{
    if (Env::isDev()) {
        return true;
    }

    if (getenv('GOOGLE_RECAPTCHA_OFF')) {
        return true;
    }

    if (empty(getenv('GOOGLE_RECAPTCHA_SECRET_KEY'))) {
        response([
            'code' => 999,
            'error' => 'Проверьте настройки reCaptcha',
        ]);
    }

    $response = post('https://www.google.com/recaptcha/api/siteverify', [
        'secret' => getenv('GOOGLE_RECAPTCHA_SECRET_KEY'),
        'response' => $gRecaptchaResponse,
    ]);

    try {
        $json = json_decode($response);

        if (!$json->success) {
            file_put_contents('/var/log/php.log', $response);
            return false;
        }

        return true;
    } catch (\Throwable $e) {
        file_put_contents('/var/log/php.log', $e->getMessage());
        return false;
    }
}

if (!validateRecaptchaResponse($_REQUEST['g-recaptcha-response'])) {
    response([
        'code' => 999,
        'error' => 'Не пройдена проверка на робота',
    ]);
}

// Подмена города для SOAP
$city = $_POST['location'];
$cityTotal = $city;
$cityExact = explode(' ', $city);
$countWords = count($cityExact);
if ($countWords > 1) {
    if (mb_strlen($cityExact[0]) <= 3) {
        $cityTotal = $cityExact[1];
    }
}
$phone = preg_replace('/[^0-9+]/', '', $_POST['phone']);
$mobile = preg_replace('/[^0-9+]/', '', $_POST['mobile']);

$xmlBody = <<<XML
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:wsli="http://www.openwaygroup.com/wsli">
   <soapenv:Header/>
   <soapenv:Body>
      <wsli:PortalCreateServiceRequestLICARD>
        <wsli:Reason>?</wsli:Reason>
        <wsli:InObject>
           // Тело формы
         </wsli:InObject>
         <wsli:UserInfo>?</wsli:UserInfo>
      </wsli:PortalCreateServiceRequestLICARD>
   </soapenv:Body>
</soapenv:Envelope>
XML;

curl_setopt_array($curl, array(
    CURLOPT_URL => '',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_ENCODING => '',
    CURLOPT_MAXREDIRS => 10,
    CURLOPT_TIMEOUT => 0,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
    CURLOPT_CUSTOMREQUEST => 'POST',
    CURLOPT_POSTFIELDS => $xmlBody,
    CURLOPT_HTTPHEADER => array(
        'Content-Type: application/xml; charset=utf-8'
    ),
));

$response = curl_exec($curl);
curl_close($curl);

$object = soapResponseToObject($response);
$retCode = $object->body->envelope->body->portalcreateservicerequestlicardresponse->retcode ?: null;
if ((string)$retCode === '0') {
    response([
        'code' => 0,
        'number' => (string)$object->body->envelope->body->portalcreateservicerequestlicardresponse->srnumber
    ]);
} else {
    switch ((int)$retCode) {
        case 500:
            $error = !empty($object->body->envelope->body->portalcreateservicerequestlicardresponse->retmsg) ? $object->body->envelope->body->portalcreateservicerequestlicardresponse->retmsg : 'Произошла ошибка при регистрации заявки';
            break;

        case 501:
            $error = 'Оформление Заявки невозможно. Вы указали адрес электронной почты, который уже используется другим пользователем. Пожалуйста, укажите другой адрес электронной почты';
            break;

        case 502:
            $error = 'Оформление Заявки невозможно. Не найдено ФИО руководителя указанной Вами организации. Пожалуйста, проверьте регистрационные данные этой организации';
            break;

        case 503:
            $error = 'Оформление Заявки невозможно. Не переданы банковские реквизиты';
            break;

        case 504:
            $error = 'Оформление заявки невозможно. Вы превысили суточный лимит заявок . Попробуйте подать заявку завтра.';
            break;

        default:
            $error = 'Произошла ошибка при регистрации заявки';
    }

    response([
        'code' => (int)$retCode,
        'error' => (string)$error,
    ]);
}

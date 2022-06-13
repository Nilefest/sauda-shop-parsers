<?

namespace Classes\Onebox;

use Classes\Logger;

class Onebox{

    // Отправить запрос в OneBox
    public function request($url, $data="")
    {

        /*$api_url = 'https://1vikavas3.crm-onebox.com/api'.$url;
        $api_key = '6d222c654e1d603db3ddb2e4be487598';
        $api_login = 'restapi';*/

        $api_url = 'https://sauda24crm.com/api'.$url;
        $api_key = 'a1927b814f2387066bdb7a763a3a752b';
        $api_login = 'restapi';

        $request = 'login='.$api_login.'&password='.$api_key;
        if (!empty($data)){
            $request .= $data;
        }

        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $api_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $request);

        $response = curl_exec($ch);
        curl_close($ch);
        $response = json_decode($response);

        return $response;
    }

    // Отправить запрос в OneBox
    public function requestNew($url, $data="")
    {

        /*$api_url = 'https://1vikavas3.crm-onebox.com/api'.$url;
        $api_key = '6d222c654e1d603db3ddb2e4be487598';
        $api_login = 'restapi';*/

        $api_url = 'https://sauda24crm.1b.app/api'.$url;
        $api_key = 'a1927b814f2387066bdb7a763a3a752b';
        $api_login = 'restapi';

        $request = 'login='.$api_login.'&password='.$api_key;
        if (!empty($data)){
            $request .= $data;
        }

        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $api_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $request);

        $response = curl_exec($ch);
        curl_close($ch);
        $response = json_decode($response);

        return $response;
    }


}
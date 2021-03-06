<?php
namespace koulab\UltimateTwitter;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\HandlerStack;
use koulab\UltimateTwitter\Exception\UltimateTwitterException;
use koulab\UltimateTwitter\Middleware\AutomatedRotateProxyMiddleware;

class Client{
    private $client;
    private $proxy;
    private $publicBearer = 'Bearer AAAAAAAAAAAAAAAAAAAAANRILgAAAAAAnNwIzUejRCOuH5E6I8xnZz4puTs%3D1Zv7ttfk8LF81IUq16cHjhLTvJu4FA33AGWWjCpTnA';

    /**
     * @return array|Proxy
     */
    public function getProxy() : Proxy
    {
        return $this->proxy;
    }

    /**
     * @param array|Proxy $proxy
     */
    public function setProxy(Proxy $proxy): void
    {
        $this->proxy = $proxy;
    }


    public function setClient(\GuzzleHttp\ClientInterface $client)
    {
        $this->client = $client;
    }

    public function getClient() : \GuzzleHttp\ClientInterface
    {
        return $this->client;
    }

    public function __construct(Proxy $proxy = null,\GuzzleHttp\ClientInterface $client = null)
    {
        $this->parser = new Parser();
        if(is_null($client) || !($client instanceof \GuzzleHttp\ClientInterface)){
            $stack = HandlerStack::create();
            $stack->after('cookies',new AutomatedRotateProxyMiddleware($proxy));
            $stack->push(\GuzzleHttp\Middleware::retry(
                function ($retries,\GuzzleHttp\Psr7\Request $request,$response,$exception){
                    if($retries >= 5){ return false; }
                    if($exception instanceof  \GuzzleHttp\Exception\RequestException || $exception instanceof \GuzzleHttp\Exception\BadResponseException){
                        return true;
                    }

                }
            ));

            $this->setClient(
                new \GuzzleHttp\Client([
                    //'debug'=>true,
                    'allow_redirects'=>true,
                    'cookies'=>true,
                    'handler'=>$stack,
                    'headers'=>[
                        'User-Agent'=>'Mozilla/5.0 (MSIE 10.0; Windows NT 6.1; Trident/5.0)',
                    ]
                ])
            );
        }
    }

    public function setParserContent($html){
        $this->parser->clear();
        $this->parser->addHtmlContent($html,'UTF-8');
    }
    public function getAuthenticityToken(){
        return $this->parser->getAuthenticityToken();
    }
    public function get($url,$params = []){
        $params['headers']['Authorization'] = $this->publicBearer;
        $aGet =  $this->getClient()->request('GET',$url,$params)->getBody()->getContents();
        $this->setParserContent($aGet);
        return $aGet;
    }
    public function post($url,$params = []){
        $params['headers']['Authorization'] =  $this->publicBearer;
        $aPost = $this->getClient()->request('POST',$url,$params)->getBody()->getContents();
        return $aPost;
    }

    public function sendDirectMessage($screenName,$message,$confirm = false){
        $target = 'https://mobile.twitter.com/'.$screenName.'/messages';

        $response = $this->getClient()->request('GET',$target.'?',[
            'headers'=>[
                'referer'=>'https://mobile.twitter.com/'.$screenName.'/actions?commit=%E2%80%A2%E2%80%A2%E2%80%A2',
            ]
        ]);

        $this->setParserContent((string)$response->getBody()->getContents());

        $response = $this->getClient()->request('POST',$target,[
            'headers'=>[
                'referer'=>'https://mobile.twitter.com/'.$screenName.'/messages?',
            ],
            'form_params'=>[
                'authenticity_token'=>$this->getAuthenticityToken(),
                'message'=>[
                    'recipient_screen_name'=>$screenName,
                    'text'=>$message,
                ],
                'commit'=>'送信'
            ]
        ]);

        if($confirm){
            $response = $this->getClient()->request('GET',$target.'?');

            $this->parser->clear();
            $content = (string)$response->getBody()->getContents();
            $this->parser->addHtmlContent($content,'UTF-8');
            return $this->parser->getMessageClassString();

        }
        return $response->getBody()->getContents();


    }


    private function verifyAccountChallenge($challengeType = 'RetypeEmail',$challengeValue = '',$tel = ''){

        if($challengeType != 'RetypeEmail'){
            $challengeValue = $tel;
        }
        $this->getClient()->request('POST','https://mobile.twitter.com/account/login_challenge',[
            'form_params'=>[
                'authenticity_token'=>$this->parser->getAuthenticityToken(),
                'challenge_id'=>$this->parser->getChallengeId(),
                'enc_user_id'=>$this->parser->getEncUserId(),
                'challenge_type'=>$challengeType,
                'platform'=>'web',
                'redirect_after_login'=>'/',
                'remember_me'=>'true',
                'challenge_response'=>$challengeValue
            ]
        ]);
    }
    public function bypassAccountChallenge(){
        try{
            $this->parser->getChallengeId();
            $this->parser->getChallengeType();
            $this->verifyAccountChallenge($this->parser->getChallengeType(),$this->email,$this->tel);

        }catch (\InvalidArgumentException $e){ }
    }
    public function login($usernameOrEmail,$password,$email = null,$tel = null){
        $this->email = $email;
        $this->tel = $tel;

        $response = $this->getClient()->request('GET','https://mobile.twitter.com/');
        $this->parser->clear();
        $this->parser->addHtmlContent($response->getBody()->getContents(),'UTF-8');

        $response = $this->getClient()->request('POST','https://mobile.twitter.com/sessions',[
            'headers'=>[
                'accept'=>'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,image/apng,*/*;q=0.8',
                'accept-encoding'=>'gzip, deflate, br',
                'accept-language'=>'ja,en-US;q=0.9,en;q=0.8',
                'cache-control'=>'max-age=0',
                'referer'=>'https://mobile.twitter.com/',

            ],
            'form_params'=>[
                'authenticity_token'=>$this->parser->getAuthenticityToken(),
                'session'=>[
                    'username_or_email'=>$usernameOrEmail,
                    'password'=>$password,
                ],
                'remember_me'=>'1',
                'wfa'=>'1',
                'commit'=>'ログイン',
                'ui_metrics'=>'',
                'scribe_log'=>'',
                'redirect_after_login'=>'',

            ]
        ]);
        if($response->getStatusCode() === 302 && strpos($response->getBody()->getContents(),'/error') !== FALSE){
            throw new UltimateTwitterException("Invalid credentials");
        }

        $this->setParserContent((string)$response->getBody()->getContents());

        return $response->getBody()->getContents();
    }


}
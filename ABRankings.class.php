<?php
//namespace App\Providers;
/**
 * Created by PhpStorm.
 * User: alldocube
 * Date: 19/04/2018
 * Time: 11:20
 */
class ABRankings
{
    private $token;
    private $domain = 'https://app.seoscout.com';
    protected static $RESTDomain = 'https://app.seoscout.com';
    protected static $defaults = array();
    protected static $option_key = 'abr_settings';


    public function __construct()
    {
        if (isset($_SERVER['HTTP_HOST'])) {
           // if($_SERVER['HTTP_HOST']=='wp.test') $this->domain='http://abranker.test';
        }

    }

    // Get a list of the users' site names and IDs
    public function getSites($token='')
    {
        try {
            $response = Requests::get($this->domain . '/api/sites?api_token=' . $token);
        } catch (\Exception $e) {
            $response = new stdClass();
            $response->body='[]';
        }
//        print_r($response);
        $sites=json_decode($response->body,TRUE);

        return $sites;
    }
    // Download all tests for a site to store locally
    public function getTests($id,$token='')
    {
        $tests_url=$this->domain.'/api/tests?api_token='.$token.'&abr_id='.$id;
//        $response=file_get_contents($tests_url);
        try {
            $response=Requests::get($tests_url);
        } catch (\Exception $e) {
            $response = new stdClass();
            $response->body='[]';
            $response->status_code = '500';
        }
//        print_r($response);
        $GLOBALS['http_response']= (int) $response->status_code;
//        echo $tests_url;
//        echo $response;
        $tests=json_decode($response->body,true);
        return $tests;
    }

    // Download test for a single url
    public function getTestsForUrl($url, $id = 0, $type='')
    {
        $ch = curl_init();

        $test_url=$this->domain.'/test?url='.urlencode($url).'&abr_id='.$id.'&type='.$type;

        curl_setopt($ch, CURLOPT_URL, $test_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET");


        $headers = array();
        if (isset($_SERVER['HTTP_USER_AGENT'])) {
            $headers[] = "User-Agent: ".$_SERVER['HTTP_USER_AGENT'];
        } else $headers[] = "User-Agent: Unknown";
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $result = curl_exec($ch);
        if (curl_errno($ch)) {
            echo 'Error:' . curl_error($ch);
            print_r(curl_errno($ch));
        }
        curl_close ($ch);
//        echo $result;
        $test=json_decode($result, TRUE);

        return $test;
    }

    // return the HTML we provide altered by a test
    public function alterHTML($html, $test, $tokens = array())
    {
        $dom=new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML($html);

        $xpath=new DOMXPath($dom);

        // grab any extra data from the DOM - tokens should already include token / meta info
        if ((isset($test['grabbers'])) && (is_array($test['grabbers'])) ) {
            foreach ($test['grabbers'] as $grabber) {
                foreach ($xpath->query($grabber['xpath']) as $element) {
                    if ($grabber['collect'] == 'attr') $token[$grabber['name']] = $element->getAttribute($grabber['attr']);
                    else $tokens[$grabber['name']] = $element->nodeValue;
                }
            }
        }
        // Make changes to the DOM based on our test values

        if ((isset($test['alters'])) && (is_array($test['alters'])) ) {
            foreach ($test['alters'] as $alter) {

                $elements = $xpath->query($alter['xpath']);

                $alter['newValue'] = (string) $this->replaceTokens($alter['newValue'], $tokens);

                if ($elements->length == 0) {
                    // create the element! How do we do this from a CSS selector/xpath? HMMMMMMMMMMMMMMMMMMMMMMMMMMM
                    // do we really want to do this for anything except 'there is no meta/title tag'? Surely most operations
                    // will be append/prepend/replacetext? EG append '<meta name="robots" content="noindex,follow">' to HEAD?
                    // ok then, lets hardcode for now:
                    if (trim($alter['selector']) == 'meta[name=\'description\']') {
                        $head = $dom->getElementsByTagName('head')->item(0);
//                        print_r($head);
                        $meta = $dom->createElement('meta');
                        $meta->setAttribute('name', 'description');
                        $meta->setAttribute('content', $alter['newValue']);
                        $head->appendChild($meta);
                    }
                } else {
                    foreach ($elements as $element) {
                        if ($alter['change'] == 'text') $element->textContent = $alter['newValue'];
                        if ($alter['change'] == 'html') $element->nodeValue = $alter['newValue'];
                        if ($alter['change'] == 'prepend') $element->nodeValue = $alter['newValue'] . $element->nodeValue;
                        if ($alter['change'] == 'append') $element->nodeValue .= $alter['newValue'];
                        if ($alter['change'] == 'attr') $element->setAttribute($alter['attr'], $alter['newValue']);

                    }
                }
                // every change to DOM means we need to regen our xpath
                $xpath = new DOMXPath($dom);
            }
        }
        return $dom->saveHTML();
    }

    public function checkTest($url, $abr_id, $GoogleCache = FALSE)
    {
//        echo $url . ':'.$abr_id;
        $test = $this->getTestsForUrl($url,$abr_id);
        $found=0;
        $checks=0;
//        print_r($test);
        if (is_array($test['alters'])) {
            $ch = curl_init();
            if ($GoogleCache) {
                // Add proxy stuff later..
                $url="http://webcache.googleusercontent.com/search?q=cache:".urlencode($url)."&cd=4&hl=en&ct=clnk&gl=uk";
            }
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET");
            curl_setopt($ch, CURLOPT_HTTPHEADER, array('Cache-Control: no-cache','Pragma: no-cache','User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/66.0.3359.181 Safari/537.36'));


            $html=curl_exec($ch);
            //        echo $html;
            $doc=new DOMDocument();

            libxml_use_internal_errors(true);
            $doc->loadHTML($html);

            $xpath=new DOMXPath($doc);

            foreach ($test['alters'] as $alter) {
                $elements = $xpath->query($alter['xpath']);
                $match = preg_replace('/{(.*)}/', '(.*)', $alter['newValue']);
//            echo $match;
                foreach ($elements as $element) {
//                echo $element->textContent;
                    if ($alter['change'] == 'text') $assert = preg_match("/^$match$/", $element->textContent);
                    // really not sure the below will work
                    if ($alter['change'] == 'html') $assert = preg_match("/^$match$/", (string)$element->nodeValue);
                    if ($alter['change'] == 'prepend') $assert = preg_match("/^$match(.*)$/", (string)$element->nodeValue);
                    if ($alter['change'] == 'append') $assert = preg_match("/^(.*)$match)$/", (string)$element->nodeValue);
                    if ($alter['change'] == 'attr') $assert = preg_match("/$match/", $element->getAttribute($alter['attr']));
                    if ($assert == TRUE) $found++;
                    $checks++;
                }
//            echo "\n";
            }
        } //else echo "No tests found";
//        echo "$found found from $checks checks";
        // do we want to return the test itself?
        return $checks>0 ?  $found/$checks : 0;
    }

    // basic token replacement
    public function replaceTokens($string, $tokens)
    {
        preg_match_all('/{{\s*(.*)\s*}}/U',$string,$matches);

        $replacements=array();

        foreach($matches[1] as $key => $token) {
            $token=trim($token);
            if (isset($tokens[trim($token)])) {
                $replacements[ $matches[0][$key] ] = $tokens[trim($token)];
            }
        }
//        print_r($tokens);
//        print_r($replacements);
        $string = strtr($string,  $replacements);
//        echo $string;
        return $string;
    }






   public static function abrankings_save_settings( array  $settings ){
		foreach ( $settings as $i => $setting ){
         update_option( $i, $setting );
		}
   }
   public static function abrankings_update_tests(){
      update_option('abr_last_updated', date('Y-m-d H:s:i',time()),true);

      $abrToken = get_option( 'abr_token', '');
      $abrID = get_option( 'abr_id', -1);
      $testsLastUpdated = get_option('abr_last_updated', '');
      try {
          $responseTests = Requests::get(self::$RESTDomain . '/api/tests?api_token=' . $abrToken .'&abr_id='.$abrID);
      } catch (\Exception $e) {
          $responseTests = new stdClass;
          $responseTests->body = '[]';
      }
      $tests = json_decode($responseTests->body,TRUE);
      return array(
         'tests' => $tests,
         'last_updated' => $testsLastUpdated
      );
   }
   public static function abrankings_get_settings(){
		$abrToken = get_option( 'abr_token', '');
      $abrID = get_option( 'abr_id', -1);
      $testsLastUpdated = get_option('abr_last_updated', '');
      try {
          $responseSites = Requests::get(self::$RESTDomain . '/api/sites?api_token=' . $abrToken);
      } catch (\Exception $e) {
          $responseSites = new stdClass;
          $responseSites->body = "[]";
      }
      $sites = json_decode($responseSites->body,TRUE);
       try {
           $responseTests = Requests::get(self::$RESTDomain . '/api/tests?api_token=' . $abrToken . '&abr_id=' . $abrID);
       } catch (Exception $e) {
           $responseTests = new stdClass;
           $responseTests->body = '[]';
       }
       $tests = json_decode($responseTests->body,TRUE);

		return array(
         'abr_token' => $abrToken,
         'abr_id' => intval($abrID),
         'sites' => $sites,
         'tests' => $tests,
         'last_updated' => $testsLastUpdated
      );
   }
   public function add_routes( ) {
		register_rest_route( 'abrankings/v1', '/settings',
			array(
				'methods'         => 'POST',
				'callback'        => array( $this, 'update_settings' ),
				'args' => array(
					'abr_token' => array(
						'type' => 'string',
						'required' => false,
						'sanitize_callback' => 'sanitize_text_field'
					),
					'abr_id' => array(
						'type' => 'integer',
						'required' => false,
						'sanitize_callback' => 'absint'
					)
				),
				'permissions_callback' => array( $this, 'permissions' )
			)
		);
		register_rest_route( 'abrankings/v1', '/settings',
			array(
				'methods'         => 'GET',
				'callback'        => array( $this, 'get_settings' ),
				'args'            => array(
				),
				'permissions_callback' => array( $this, 'permissions' )
			)
		);
		register_rest_route( 'abrankings/v1', '/refresh-tests',
			array(
				'methods'         => 'POST',
				'callback'        => array( $this, 'refresh_tests' ),
				'args'            => array(
				),
				'permissions_callback' => array( $this, 'permissions' )
			)
		);
   }

   public function permissions(){
		return current_user_can( 'manage_options' );
   }

   public function refresh_tests( WP_REST_Request $request ){
		return rest_ensure_response( $this->abrankings_update_tests() );
   }

   public function update_settings( WP_REST_Request $request ){
		$settings = array(
			'abr_token' => $request->get_param( 'abr_token' ),
			'abr_id' => $request->get_param( 'abr_id' )
		);
		$this->abrankings_save_settings( $settings );
		return rest_ensure_response( $this->abrankings_get_settings() );
   }

   public function get_settings( WP_REST_Request $request ){
		return rest_ensure_response( $this->abrankings_get_settings() );
	}


}

<?php
  define('API_KEY', '');
  define('OpenID_OrgID', '');

	class vsChatGPT {
		private $api_host = 'https://api.openai.com/v1/';
		public $userID;

		public function __construct(){
		}

		/*
			@para
				$request	STRING
				$payload	ARRAY
				$method		STRING	(GET / POST)
		
			@return
				$resonse	ARRAY | STRING
		*/
		private function callAPI($request, $payload=false, $method='POST'){
			$headers = [];
			if(array_key_exists('file', $payload)) $headers[] = 'Content-Type: multipart/form-data';
            else$headers[] = 'Content-Type: application/json';
            $headers[] = 'Authorization: Bearer '. API_KEY;
            $headers[] = 'OpenAI-Organization: '. OpenID_OrgID; 
		
			$ch = curl_init($this->api_host . $request);
            curl_setopt($ch, CURLOPT_TIMEOUT, 120);
            curl_setopt($ch, CURLOPT_HEADER, 0);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);

			if($payload){
				if(array_key_exists('file', $payload)) curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
				else curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
			}
			
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
			
			$response = curl_exec($ch);
			if(curl_errno($ch) == CURLE_OPERATION_TIMEDOUT) {
				$response = json_encode(['status'=>'error', 'msg'=>'timeout', 'full_msg'=>curl_error($ch)]);
			}
			curl_close($ch);

			return json_decode($response, true);
		}



		// Corrects sentences into standard English
		public function correctGrammer($text, $model='text-davinci-003'){
			$para = [];
      $para['model'] = $model;
      $para['prompt'] = ('Correct this to standard English: '. $text);
      $para['temperature'] = 0;
      $para['top_p'] = 1.0;
      $para['frequency_penalty'] = 0.0;
      $para['presence_penalty'] = 0.0;
      $para['max_tokens'] = 1000;

			return $this->callAPI('completions', $para);
		}



		public function chat($prompt, $msgs=false, $model='gpt-3.5-turbo'){
      $msgs = (is_array($msgs) ? $msgs : []);
      $msgs[] = ['role'=>'user', 'content'=>$prompt];

      $para = [];
      $para['model'] = $model;
      $para['messages'] = $msgs;
      $payload['temperature'] = 0.7;
      if($this->userID) $payload['user'] = $this->userID;

			return $this->callAPI('chat/completions', $para);
		}
		
		
		
		
		
		
		/****
		 Images
		 ****/
		
		/*
			@desc	Generate a Dell-E image
			@para
				$prompt	STRING
				$size	STRING	(256x256, 512x512, 1024x1024)
				$num	INT		(1-10) Number of results to show
				$format	STRING	(Return Type: url, image)
		
			@return
				$payload
		 */
		public function createIMG($prompt, $size='256x256', $num=1, $format='url'){
			$para = [];
			$para['prompt'] = $prompt;
			$para['size'] = (is_numeric($size) ? ($size .'x'. $size) : $size);
			$para['response_format'] = $format;
			$para['n'] = MIN($num, 10);
			if($this->userID) $para['user'] = $userID;

			$results = $this->callAPI("images/generations", $para);
			if($results && count($results['data']) > 0) return $results['data'];
			else return ['status'=>'error', 'msg'=>'Unable to generate image'];
		}


		/****
         Audio
         ****/
        /*
        	@para
        		$file	STRING	File path audio file (max of 25mb)
        		$prompt	STRING	Helper text
        		$model	STRING	Model you wanna use (whisper-1 is only available)
        
        	@return
        		$text	STRING Transcript of audio file
        */
		public function createTranscription($file, $prompt='', $model='whisper-1'){
			$para = [];
			if($prompt != '') $para['prompt'] = trim($prompt);
			$para['model'] = $model;
			$para['response_format'] = 'json';
			$para['file'] = curl_file_create($file, 'audio.mp3');
			
			$results = $this->callAPI('audio/transcriptions', $para);
			if($results && isset($results['text'])) return $results;
			else return ['status'=>'error', 'msg'=>'Unable to process'];
		}
	}
?>

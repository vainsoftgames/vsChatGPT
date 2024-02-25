<?php
	class vsChatGPT {
		public $api_host = 'https://api.openai.com/v1/';
		public $userID;
		public $timeout = 120;
		public $fncs;
		public $tools;
		public $max_tokens = 1024;
		
		public $db;
		public $log = false;
		public $log_type = 'sys';
		public $log_fnc = null;

		public function __construct(){
			if(!function_exists('curl_init')){
				throw new Exception('cURL isn\'t installed.');
				return false;
			}
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
            $headers[] = 'OpenAI-Organization: '. OpenAI_OrgID; 

			$r_headers = [];
		
			$ch = curl_init($this->api_host . $request);
            curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
            curl_setopt($ch, CURLOPT_HEADER, 0);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
			curl_setopt($ch, CURLOPT_ENCODING, 'gzip');
			curl_setopt($ch, CURLOPT_HEADERFUNCTION,
				function($curl, $header) use (&$r_headers) {
					$len = strlen($header);
					$header = explode(':', $header, 2);
					if (count($header) < 2) // ignore invalid headers
						return $len;

					$r_headers[strtolower(trim($header[0]))][] = trim($header[1]);

					return $len;
				}
			);

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

			$json = json_decode($response, true);
			if($json && is_array($json)){
				$json['headers'] = $r_headers;
			}
			return $json;
		}
		/*
			Get list of models available to account
			@para
				$model		STRING|ARRAY (Model you are looking for or default to false to return all models)

			@return
				$response	ARRAY (List of models)
					id		STRING
					object		STRING (model)
					created		INT16	UNIX Timestamp
					owned_by	STRING
					permission	ARRAY
						id			STRING
						object			STRING
						created			INT16 Unix Timestamp
						allow_create_engine	BOOLEAN
						allow_sampling		BOOLEAN
						allow_logprobs		BOOLEAN
						allow_search_indices	BOOLEAN
						allow_view		BOOLEAN
						allow_fine_tuning	BOOLEAN
						organization		STRING
						group			STRING
						is_blocking		BOOLEAN
					root		STRING
					parent		STRING
		*/
		public function checkModels($model=false){
			// Check if user supplied string array
			if(is_string($model) && strpos(',', $model)) $model = explode(',', $model);

			$endpoint = 'models';
			if($model && is_string($model)) $endpoint .= '/'. $model;

			$results = $this->callAPI($endpoint, NULL, 'GET');
			// Single model returns don't return an list array
			if($results && isset($results['id'])) return [$results];
			else if($results && isset($results['data'])) {
				// Check if user is looking for multiple models
				if(is_array($model)){
					$newList = [];
					foreach($results['data'] as $item){
						if(in_array($item['id'], $model)) $newList[] = $item;
					}
					
					return $newList;
				}
				else return $results['data'];
			}
			else return false;
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


		public function fnc($function_call, &$response, &$msgs, $isTool=false){
			if($this->log_type == 'sys') error_log(__METHOD__ . ": " . json_encode($function_call));
			$function = $function_call['function'];
			$arg = @json_decode($function['arguments'], true);
			$fnc_results = false;
			if(function_exists($function['name'])){
				$response['args'][] = ['name'=>$function['name'], 'arg'=>$arg];
				$fnc_results = $function['name']($this, $arg);
			}
			else $response['bad_fnc'][] = $function['name'];

			$msg = [];
			$msg['name'] = $function['name'];
			if($isTool){
				$msg['role'] = 'function';
				$msg['tool_call_id'] = $function_call['id'];
			}
			else $msg['role'] = 'function';

			if($fnc_results){
				$msg['content'] = json_encode($fnc_results['payload']);
			}
			else $msg['content'] = 'Unable to find function';
			
			$msgs[] = $msg;
		}
		public function processPayload($msgs, $model=false, &$response){
			if(!$model) $model = 'gpt-3.5-turbo-16k';
			if(!isset($response['tries'])) $response['tries'] = 0;

			// Track Start Time
			$response['time']['start'] = time();
			$results = $this->chatNop($msgs, $model);

			// Track End Time
			$response['time']['end'] = time();

			// Track how long ChatGPT took to respond
			$response['time']['dur'] = abs($response['time']['start'] - $response['time']['end']);

			if(isset($results['id'])){
				$response['id'] = $results['id'];
				$response['tokens'] = $results['usage'];

				if($this->log){
					if($this->log_fnc){
						call_user_func(
							$this->log_fnc,
							(isset($task['userID']) ? $task['userID'] : 0),
							$results['usage'],
							(isset($response['type']) ? $response['type'] : false),
							$results['model']
						);
					}
				}

				if(isset($results) && isset($results['choices']) && count($results['choices']) > 0){
					$choice = $results['choices'][0]['message'];

					if(isset($choice['tool_calls']) && count($choice['tool_calls']) > 0){
						foreach($choice['tool_calls'] as $tool){
							$this->fnc($tool, $response, $msgs, true);
						}

						return $this->processPayload($msgs, $model, $response);
					}
					else if(isset($choice['function_call'])){
						$this->fnc($choice['function_call'], $response, $msgs);

						return $this->processPayload($msgs, $model, $response);
					}
					else {
						$response['payload'] = trim($choice['content']);
						$response['status'] = 'success';
						$response['model'] = $results['model'];

						if(isset($response['decode'])){
							$response['payload_decode'] = substr($response['payload'], strpos($response['payload'], '{'));
							$response['payload_decode'] = substr($response['payload_decode'], 0, strrpos($response['payload_decode'], '}')+1);
							$response['payload_decode'] = json_decode($response['payload_decode'], true);
						}
					}

					return true;
				}
				else {
					$response['status'] = 'error';
					$response['msg'] = 'No choices returned';
					$response['openai'] = $results;
				}
			}
			else if(isset($results['error'])){
				// If server error, usually when overloaded. Try again in a second
				if($results['error']['type'] == 'server_error' && $response['tries'] < 3){
					$response['tries']++;
					sleep(2);
					return $this->processPayload($msgs, $model, $response);
				}
				// If prompt exceeds GPT-3.5, upgrade to GPT-4
				else if($results['error']['code'] == 'context_length_exceeded' && strpos($model, 'gpt-3.5-turbo-16k') === false){
	//                                 $task['para']['upgraded'] = true;
					return $this->processPayload($msgs, 'gpt-3.5-turbo-16k', $response);
				}
				else {
					$response['status'] = 'error';
					$response['msg'] = 'Error generating from ChatGPT';
					$response['openai'] = $results;
				}
			}
			else {
				$response['status'] = 'error';
				$response['msg'] = 'Error generating from ChatGPT';
				$response['openai'] = $results;
			}

			return false;
		}

		/****
		 Chat
		 ****/
		/*
		
		
		*/
		public function createEProp() : array {
			return $this->createProp('');
		}
		public function createEProps() : array {
			$props = [];
			$props['placeholder'] = $this->createEProp();
			return $props;
		}
		public function createProp($desc, $type='string', $emu=null){
			$payload = [];
			$payload['type'] = $type;
			$payload['description'] = trim(str_replace("\r\n", '', $desc));

			return $payload;
		}
		public function createMSG($role, $text, ...$images){
			return $this->createMSGFull($role, null, $text, null, ...$images);
		}
		public function createMSGFull($role='user', $name=null, $text, $detail='low', ...$images){
			$msg = [];
			$msg['role'] = $role;
			if($name !== null && $name != '') $msg['name'] = $name;

			if(!empty($images)){
				$msg['content'] = [];
				if($text !== null && $text != '') $msg['content'][] = ['type'=>'text', 'text'=>$text];
				$detail = $detail ?: 'low';

				foreach($images as $img){
					if(file_exists($img) && is_file($img)){
						$fileExt = strtolower(pathinfo($img, PATHINFO_EXTENSION));
						if($fileExt == 'jpg' || $fileExt == 'jpeg') $base64Pre = 'data:image/jpeg;base64,';
						else if($fileExt == 'png') $base64Pre = 'data:image/png;base64,';
						else if($fileExt == 'gif') $base64Pre = 'data:image/gif;base64,';
						else if($fileExt == 'webp') $base64Pre = 'data:image/webp;base64,';
						// Unsupported Image Type
						else {
							continue;
						}
						
						$msg['content'][] = [
							'type'		=>'image_url',
							'image_url'	=> [
								'url'		=> ($base64Pre . base64_encode(file_get_contents($img))),
								'detail'	=> $detail
							]
						];
					}
					else if (filter_var($img, FILTER_VALIDATE_URL) || strpos($img, 'http://') !== false || strpos($img, 'https://') !== false) {
						$msg['content'][] = [
							'type'		=>'image_url',
							'image_url'	=> [
								'url'		=> $img,
								'detail'	=> $detail
							]
						];
					}
					else continue;
				}
				return $msg;
			}
			else {
				$msg['content'] = $text;
			}
			
			return $msg;
		}
		/*
			@para
				$prompt			STRING	Message you wanna send to Chat API
				$msgs			ARRAY	Array of previous system/assistant/user messages and now function msgs
				$model			STRING	What model do you wanna use
				$para			ARRAY	Any additional parameters you wanna define
				$fncs			ARRAY	Defined functions you want Chat API to try to access in a response
			@return
				$response
		*/
		public function chat($prompt, $msgs=false, $model='gpt-3.5-turbo', $para=false, $fncs=false){
			$msgs = (is_array($msgs) ? $msgs : []);
			$msgs[] = ['role'=>'user', 'content'=>$prompt];
	
			// Call Chat NoP
			return $this->chatNop($msgs, $model, $para, $fncs);
		}
		/*
			Same as above, but without the prompt
			I use this if I already have built up a $msgs and don't need a user prompt
		*/
		public function chatNop($msgs=false, $model='gpt-3.5-turbo', $para=false, $fncs=false){
			$para = (isset($para) && is_array($para) ? $para : []);
			$para['model'] = $model;
			$para['messages'] = $msgs;
		   	 if(!isset($para['temperature'])) $para['temperature'] = 0.7;
	
			// Check if user defined, userID
			if($this->userID) $para['user'] = $this->userID;
			if(!isset($para['max_tokens'])) $para['max_tokens'] = $this->max_tokens;
		    
		    
		   	 if(!isset($para['functions'])){
				// Check if user defined functions just for this instance
				if($fncs && is_array($fncs)) $para['functions'] = $fncs;
				// Check for global functions user wants to use everytime
				else if($this->fncs) $para['functions'] = $this->fncs;
			}
			if(!isset($para['tools'])){
				// Check for global functions user wants to use everytime
				if($this->tools) $para['tools'] = $this->tools;
			}

			return $this->callAPI('chat/completions', $para);
		}
		
		
		/*
			Create function for Chat API
			
			@para
				$name		STRING			Name of the function, if OpenAI returns it, you can cross reference
				$desc		STRING			Description of what the functions does
				$props		ARRAY			All the paramters you want OpenAI to try to generate for your function
				$required	STRING|ARRAY	What paramters are required to use your function
				
			@response		ARRAY
		*/
		public function createTool($name, $desc, $props=[], $required=false){
			$tool = [];
			$tool['type'] = 'function';
			$tool['function'] = $this->createFNC($name, $desc, $props, $required);
			
			return $tool;
		}
		public function createFNC($name, $desc, $props, $required=false){
			$fnc = [];
			$fnc['name'] = $name;
			$fnc['description'] = $desc;
			$fnc['parameters'] = [];
			$fnc['parameters']['type'] = 'object';
			$fnc['parameters']['properties'] = $props;
		
			if($required){
				$fnc['parameters']['required'] = (is_array($required) ? $required : [$required]);
			}

			return $fnc;
		}
		private function createFNC_para($props, $required=false){
			$payload = [];
			$payload['type'] = 'object';
			$payload['properties'] = $props;
			if($required) $payload['required'] = (is_array($required) ? $required : [$required]);
			
			return $payload;
		}
		private function createFNC_response($desc=false, $props, $required=false){
			$payload = [];
			if($desc) $payload['description'] = $desc;
			$payload['content'] = [
				'application/json'	=> [
					'schema'	=> $this->createFNC_para($props, $required)
				]
			];
			
			return $payload;
		}
		public function createFNC_body($name, $desc, $type, $props, $r_props, $required=false){
			$fnc = [];
			$fnc[$type] = [];
			$fnc[$type]['operationId'] = $name;
			$fnc[$type]['summary'] = $desc;
			$fnc[$type]['requestBody'] = $this->createFNC_response(false, $props, $required);

			$responses = [];
			$responses[200] = $this->createFNC_response('Successful response', $r_props);
			$fnc[$type]['responses'] = $responses;

			return $fnc;
		}
		
				
		/****
		 Moderation
		 ****/
		public function moderation($prompt, $para=false, $model='text-moderation-latest'){
            $para = ($para && is_array($para) ? $para : []);
			$para['input'] = $prompt;
			$para['model'] = $model;

			return $this->callAPI('moderations', $para);
		}
		
		
		/****
		 Completions
		 ****/
		public function completion($prompt, $para, $model='text-davinci-003'){
            $para = ($para && is_array($para) ? $para : []);
            $para['prompt'] = $prompt;
            $para['model'] = $model;
            $para['temperature'] = 0.2;
            if($this->userID) $para['user'] = $this->userID;

			return $this->callAPI('completions', $para);
		}
		
		/****
		 Files
		 ****/
		public function file_list(){
			$results = $this->callAPI('files', false, 'GET');
			if($results && isset($results['data'])) return $results['data'];
			else return false;
		}

		/*
			Upload asset for other features
			@para
				$file		STRING	File path to asset
				$purpose	STRING	Whats the purpose for this asset? Default "fine-tune"

			@return
				$results	ARRAY
					object			STRING Type of file
					id				STRING 
					purpose			STRING
					filename		STRING Full path of local upload path
					bytes			INT16
					created_at		INT16 UnixTimestamp
					status			STRING Status of file upload
					status_details	NULL
		*/
		public function file_upload($file, $purpose='fine-tune'){
			if(!file_exists($file)) return ['status'=>'error','msg'=>'file not found'];

			$para = [];
			$para['purpose'] = $purpose;
			$para['file'] = curl_file_create($file, 'chatgpt.jsonl');
			return $this->callAPI('files', $para);
		}
		/*
			Delete asset (must be owner of asset)
			@para
				$fileID	STRING id of asset

			@return
				$response	array
					id		STRING
					deleted	BOOLEAN
					error	ARRAY	If file is not found or other error
		*/
		public function file_delete($fileID){
			$results = $this->callAPI("files/{$fileID}", false, 'DELETE');
			if(isset($results['deleted']) && $results['deleted']) return true;
			else if(isset($results['error'])) return $results['error'];
			else return $results;
		}
		/*
			Returns details about file, look at Upload for returned object

			@para
				$fileID		STRING
			
			@return
				$response	ARRAY
					object			STRING Type of file
					id				STRING 
					purpose			STRING
					filename		STRING Full path of local upload path
					bytes			INT16
					created_at		INT16 UnixTimestamp
					status			STRING Status of file upload
					status_details	NULL
		*/
		public function file_get($fileID){
			return $this->callAPI("files/{$fileID}", false, 'GET');
		}
		/*
			Returns contents of file

			@para
				$fileID		STRING
			
		*/
		public function file_getContent($fileID){
			return $this->callAPI("files/{$fileID}/content", false, 'GET');
		}

		/****
		 FineTuning [WIP]
		 ****/
		/*
			Prep data for finetuning, it wants a JSON List (jsonl file)

			@para
				$payload	ARRAY Array of json encoded objects
					prompt		STRING	The question
					completion	STRING	The answer

			@return
				$response	STRING	Multi-line list of json encoded objects
		*/
		public function prepFinetune($payload){
			if(!is_array($payload)) $payload = [$payload];
			
			return implode("\n", $payload);
		}
		/*
			Request finetuning on a file (jsonl) you uploaded
			
			@para
				$fileID		STRING	`id` from file_upload
				$model		STRING	What model you want to train against, Defaults to curie
				$para		ARRAY	All optional
					validation_file					STRING The ID of an uploaded file that contains validation data.
					n_epochs
					batch_size
					learning_rate_multiplier
					prompt_loss_weight
					compute_classification_metrics
					classification_n_classes
					classification_positive_class
					classification_betas
					suffix							STRING If you want to prepend characters to your trained model name (ex: ada:ft-your-org:custom-model-name-2022-02-15-04-21-04)
			
			@return
				$response	ARRAY
					id							STRING
					object						STRING
					model						STRING
					created_at					INT16	UnixTimestamp
					events						ARRAY
						object
						created_at
						level
						message
					fine_tuned_model			ARRAY
					hyperparams					ARRAY
						batch_size
						learning_rate_multiplier
						n_epochs
						prompt_loss_weight
					result_files				ARRAY
					status						STRING
					validation_files			ARRAY
					training_files				ARRAY
					updated_at					INT16	UnixTimestamp
		*/
		public function finetune_train($fileID, $model=false, $para=false){
			$para = (isset($para) && is_array($para) ? $para : []);
			if($model) $para['model'] = $model;
			$para['training_file'] = $fileID;

			return $this->callAPI("fine-tunes", $para);
		}
		/* Get FineTuning Jobs List [WIP] */
		public function finetune_list(){
			return $this->callAPI('fine-tunes', false, 'GET');
		}
		/* Get FineTuning Job File [WIP] */
		public function finetune_file($fine_tune_id){
			return $this->callAPI("fine-tunes/{$fine_tune_id}", false, 'GET');
		}
		/* Cancel FineTuning Job [WIP] */
		public function finetune_cancel($fine_tune_id){
			return $this->callAPI("fine-tunes/{$fine_tune_id}/cancel");
		}
		/* Cancel FineTuning Events for Job [WIP] */
		public function finetune_events($fine_tune_id){
			return $this->callAPI("fine-tunes/{$fine_tune_id}/events", false, 'GET');
		}
		/*
			Delete a fine-tuned model. You must have the Owner role in your organization.
			@para
				$model	STRING	The model to delete

			@response	ARRAY
				id		STRING
				object	STRING
				deleted	BOOLEAN
		*/
		public function finetune_modelDel($model){
			$results = $this->callAPI("models/{$model}", false, 'DELETE');
			if(isset($results['deleted']) && $results['deleted']) return true;
			else if(isset($results['error'])) return $results['error'];
			else return $results;
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
		public function createIMG($prompt, $size='256x256', $num=1, $format='url', $model='dall-e-3'){
			if($this->log_type == 'sys') error_log(__METHOD__ . ": " . json_encode(['promot'=>$prompt, 'size'=>$size, 'num'=>$num, 'format'=>$format, 'model'=>$model]));

			$size = (is_numeric($size) ? ($size .'x'. $size) : $size);
			if($model == 'dall-e-3') $sizes = ['256x256','512x512','1024x1024'];
			else if($model == 'dall-e-2') $sizes = ['1024x1024','1792x1024','1024x1792'];
			else {
				return ['status'=>'error', 'msg'=>'Unsupported model'];
			}

			if(!in_array($size, $sizes)){
				$size = $sizes[0];
			}
			
			
			$para = [];
			$para['prompt'] = $prompt;
			$para['size'] = (is_numeric($size) ? ($size .'x'. $size) : $size);
			$para['response_format'] = $format;
			$para['n'] = MIN($num, 10);
			if($this->userID) $para['user'] = $userID;

			$results = $this->callAPI("images/generations", $para);
			if($results && isset($results['data']) && count($results['data']) > 0) return $results['data'];
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
		public function createTranscription($file, $prompt='', $model='whisper-1', $lang='en'){
			$para = [];
			if($prompt != '') $para['prompt'] = trim($prompt);
			$para['model'] = $model;
			$para['response_format'] = 'json';
			$para['language'] = $lang;
			$para['file'] = curl_file_create($file, ('audio.'. pathinfo($file, PATHINFO_EXTENSION)));
			
			$results = $this->callAPI('audio/transcriptions', $para);
			if($results && isset($results['text'])) return $results;
			else return ['status'=>'error', 'msg'=>'Unable to process'];
		}
		
		/*
			Text to Speech
		*/
		public function createSpeech($prompt, string $voice='alloy', string $model='tls-1', string $format='mp3', float $speed=1.0){
			$speed = max(0.25, min($speed, 4));

			$para = [];
			$para['input'] = $prompt;
			$para['voice'] = $voice;
			$para['model'] = $model;
			$para['return_format'] = $format;
			$para['speed'] = $speed;
			$para['raw'] = true;

			return $this->callAPI('audio/speech', $para);
		}



		/*
			Token Estimator
        	@para
        		$text	STRING	Input text

        	@return
        		$count	INT		Estimate of payload tokens
		*/
		private function estTokenARR($arr){
			$text = '';
			foreach($arr as $k=>$v){
				$text .= $v['role'] .' => '.$v['content'] ."\n";
			}
			
			return $text;
		}
		public function estTokens($text) {
			if(is_array($text)) $text = $this->estTokenARR($text);
			// Remove leading/trailing white spaces
			$text = trim($text);

			// Split the text into words
			$words = str_word_count($text, 1);

			// Estimate token count based on words and punctuation
			$punctuationTokens = preg_match_all("/[^\s\w]/u", $text, $matches);
			return count($words) + $punctuationTokens;
        }
	}
?>

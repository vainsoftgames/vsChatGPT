<?php
	class vsChatGPT {
		private $api_host = 'https://api.openai.com/v1/';
		public $userID;
		public $timeout = 120;

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
            curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
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



		/****
		 Chat
		 ****/
		/*
		
		
		*/
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
						$msg['content'][] = [
							'type'		=>'image_url',
							'image_url'	=> [
								'url'		=> base64_encode(file_get_contents($img)),
								'detail'	=> $detail
							]
						];
					}
					else if (filter_var($img, FILTER_VALIDATE_URL)) {
						$msg['content'][] = [
							'type'		=>'image_url',
							'image_url'	=> [
								'url'		=> $img,
								'detail'	=> $detail
							]
						];
					}
					else $msg['content'][] = 'nope: '. $img;
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
?>

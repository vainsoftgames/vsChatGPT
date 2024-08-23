# vsChatGPT
ChatGPT in PHP
This is an “unofficial" OpenAI library that I maintain.



Add vsChatGPT to your script
<br>You should pull your API Keys from environment variables, not hard cold them into your PHP files.
```php
  define('API_KEY', 'YOUR OPENAI KEY');
  define('OpenAI_OrgID', 'YOUR OpenAI ORG ID');

  require('vsChatGPT.php');
```


To start using it:
```php
  $chatgpt = new vsChatGPT();
  $chatgpt->userID = 'Set UserID if you want OpenID to monitor abuse';
  
  // Correct Grammer
  $chatgpt->correctGrammer('text you want corrected');
  
  // Chat Completions
  $prompt = 'Message you wanna send to ChatGPT';
  $msgs = [];
  $msgs[] = ['role'=>'system','content'=>'You are an AI Assistant'];
  or
  $msgs[] = $chatgpt->createMSG('system', 'You are an AI Assistant');
  $chatgpt->chat($prompt, $msgs);

```

GPT Vision
```php
  // GPT Vision
  // Define Role, Text payload & images. Can be local image or remote images. Supports multiple images.
  $msgs[] = $chatgpt->createMSG('user', 'What does this image contain?', 'https://upload.wikimedia.org/wikipedia/commons/thumb/d/dd/Gfp-wisconsin-madison-the-nature-boardwalk.jpg/2560px-Gfp-wisconsin-madison-the-nature-boardwalk.jpg');

  $msgs[] = $chatgpt->createMSG('user', 'What does this image contain?', 'my_image.jpg', 'https://upload.wikimedia.org/wikipedia/commons/thumb/d/dd/Gfp-wisconsin-madison-the-nature-boardwalk.jpg/2560px-Gfp-wisconsin-madison-the-nature-boardwalk.jpg');
```  


```php
  // Create Image (Dall-E)
  $prompt = 'Prompt you want to generate image with';
  $size = 256x256, 512x512 or 1024x1024
  $num = How many images you want returned
  $format = What do you want returned? URL? Image?
  $chatgpt->createIMG($prompt, $size, $num, $format);
  
  
  // Create Transcription
  $file = Local path to audio file
  $prompt = Text to help guide the transcription with corrections
  $chatgpt->createTranscription($file, $prompt);
  
  
  // Get list of all models you have access to
  $chatgpt->checkModels();
 
  // See if you have access to a single model
  $chatgpt->checkModels('model_name');
  
  // See if you have access to multiple models
  $chatgpt->checkModels(['gpt-4','gpt-3.5-turbo']);

```

```php
  // Finetuning
  // Once training is complete(success), then you can plug in the model name into other functions to use that model
  // Upload JSONL file for finetuning
  $file = 'chatgpt.jsonl';
  $chatgpt->file_upload($file);
  
  // Start Training
  $fileID = 'ID returned from file_upload';
  $model = 'What model do you want as your base';
  $chatgpt->finetune_train($fileID, $model);
  
  // Check progress
  $fine_tune_id = 'ID returned from finetune_train';
  $chatgpt->finetune_events($fine_tune_id);
```

## Create Speech
```php
  $prompt = 'Hello there, how can I assit you today?';
  $audio = $chatgpt->createSpeech($prompt, 'alloy', 'tts-1', 'mp3');
```

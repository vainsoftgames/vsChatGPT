# vsChatGPT
ChatGPT in PHP



Add vsChatGPT to your script
```
  define('API_KEY', 'YOUR OPENAI KEY');
  define('OpenID_OrgID', 'YOUR OpenAI ORG ID');

  require('vsChatGPT.php');
```


To start using it:
```
  $chatgpt = new vsChatGPT();
  $chatgpt->userID = 'Set UserID if you want OpenID to monitor abuse';
  
  // Correct Grammer
  $chatgpt->correctGrammer('text you want corrected');
  
  // Chat Completions
  $prompt = 'Message you wanna send to ChatGPT';
  $msgs = [];
  $msgs[] = ['role'=>'system','content'=>'You are an AI Assistant'];
  $chatgpt->chat($prompt, $msgs);
  
  
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

# vsChatGPT
ChatGPT in PHP



Add vsChatGPT to your script
```
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
```

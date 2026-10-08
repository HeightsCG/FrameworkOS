# Walkthrough scripts 21–35 (AI influencer pipeline)

Narration lines are verbatim from `/private/var/www/contentos.cvk/framework/libs/Classes/Tutorials.php` — keep them exactly, they are shown as the
transcript under the player. Each line has a **Screen** direction: what to do while that line is spoken.
Record one take per video, hold on the final frame for a second, cut long generation waits down to a short
"generating" beat (same pacing as videos 01–20).

## Setup (all videos)

- Site: `http://framework.contentos.cvk`, logged in as the **Demo Account**. Browser window 1920×1080, no
  bookmarks bar, 100% zoom.
- Influencer: **Sienna** (trained; dark-brown wavy hair, hazel eyes, beauty mark under the left eye). Her
  pages: `/influencers/create/49` (settings), `/influencers/images/49`, `/influencers/videos/49`,
  `/influencers/references/49`, `/influencers/voice/49`.
- Library already holds: a photo of "Marco" (young man, short curly hair, mustard corduroy jacket, Lisbon
  street) and a 6-second video of him walking — use these wherever a photo or video "of someone else" is
  needed. Sienna's own generated photos are there too (café photo = good close-up).
- Credits: each video's generations cost 50–350 credits; the account has ~1,800.
- Video 35 needs an Instagram or Facebook account connected under Settings → Integrations (the Story toggle
  only appears for those platforms). Without one, skip the Story click and hover Distribution instead.
- Output: 1920×1080, H.264, 30 fps, mono AAC — drop in as `/private/var/www/contentos.cvk/framework/public/videos/tutorials/NN.mp4` + `NN.jpg`
  poster, `chmod 644`, set `secs` in `Tutorials.php`.
- Title card (first 2 s): black, kicker "CREATOR LINK STUDIO · WALKTHROUGH", orange numbered badge, title,
  section name — same layout as 01–20 in the current black/orange brand.

---

## 21 · Give Your Influencer a Persona  (AI Influencers)

1. "A persona tells every AI writer who your influencer is, so captions, messages and automations sound like one person."
   **Screen:** `/influencers` — Sienna's card in view.
2. "Open Influencers, click the menu on your influencer, then Settings."
   **Screen:** click the ⋯ on her card → Settings. Scroll so the Persona box is visible.
3. "Under Persona, describe who she is in a paragraph or two."
   **Screen:** type into Description: *25, grew up in Porto, moved to Lisbon for design school and stayed. Works as a freelance illustrator, lives near the river with a very old cat.*
4. "Fill in her personality, how she speaks, her niche and what makes her vulnerable."
   **Screen:** Personality *Warm, curious, a little dry. Laughs easily.* · Way Of Speaking *Short sentences, lowercase energy, no exclamation marks.* · Niche *Slow mornings, sketchbooks, coffee and city walks.* · Vulnerability *Second-guesses her work before posting it.*
5. "Click Save. New captions and replies are written in her voice from now on."
   **Screen:** click Save Changes; hold on the success toast.

## 22 · Build Her Angle Reference Set  (AI Influencers)

1. "Angle references keep your influencer looking the same from every side."
   **Screen:** `/influencers/create/49`.
2. "Open Influencers, then References."
   **Screen:** click the References tab in the top row.
3. "Click Generate Angle Set. You get three front close-ups, both profiles, a back view and two full body shots."
   **Screen:** click the generate button in the bar (it reads "Generate 7 Missing" — the body shot already exists). Cut the wait; show the finished 8-slot sheet.
4. "Click Regenerate on any that do not look like her. The rest are used automatically."
   **Screen:** hover a Regenerate button on one slot (don't click).
5. "All of her angles are used automatically in Replicate Photo, Carousel and video."
   **Screen:** hover the Generate Images tab.

## 23 · Replicate a Photo  (AI Influencers)

1. "Replicate Photo recreates any photo with your influencer in it."
   **Screen:** `/influencers/create/49`.
2. "Open Influencers, Generate Images, then Replicate Photo."
   **Screen:** click Generate Images tab → Replicate Photo sub-tab.
3. "Choose a source photo from your Library or upload one. The face in it is hidden automatically."
   **Screen:** click Choose Image → pick Marco's photo. Cut the "reading" wait; show the photo with the face masked and the written prompt.
4. "Pick Style to recreate the scene and pose, or Exact to swap her in and keep the composition."
   **Screen:** open the Mode chip, hover Exact, choose Style.
5. "Edit the prompt if you want, choose a size, and click Replicate."
   **Screen:** add ", golden hour" to the end of the prompt, open the Size chip, click Replicate Photo. Cut the wait; show the result beside the grid.

## 24 · Generate a Carousel  (AI Influencers)

1. "A carousel is several shots of one moment, with the outfit and location held the same."
   **Screen:** `/influencers/images/49`.
2. "Open Influencers, Generate Images, then Carousel."
   **Screen:** click the Carousel sub-tab.
3. "Add a seed image or describe the scene, then pick what should vary and how many images you want."
   **Screen:** hover Choose Image, then type *Sunday market in Lisbon, linen shirt and sunglasses, browsing the fruit stalls*; set what varies; set 4 images.
4. "Click Generate Carousel. Reorder, drop or regenerate any image."
   **Screen:** click Generate Carousel. Cut the wait; show the 4 images; hover the reorder/drop controls on one.
5. "Click Use In Post to open a draft with the images in that order."
   **Screen:** click Use In Post; hold on the composer with the 4 images attached.

## 25 · Edit an Image by Instruction  (Content Studio)

1. "Change one thing in a photo by describing it."
   **Screen:** Content Studio → Library tab.
2. "Open an image in your Library and click Edit."
   **Screen:** click Marco's photo → File Details opens → click Edit.
3. "Type the change, for example make the dress red, and click Apply Edit."
   **Screen:** type *make the jacket navy blue*, click Apply Edit. Cut the wait; show the edited image.
4. "The edit is saved as a new version. The original stays in your Library."
   **Screen:** close the Edit window; hover the Versions list in File Details.

## 26 · Use Scene Templates  (Influencers → Generate Images → Scenes)

1. "Scenes are ready-made ideas you can run with any of your influencers."
   **Screen:** `/influencers/images/<id>`.
2. "Open Influencers, Generate Images, then Scenes. Click New Scene to save an idea of your own; the platform scenes sit beside it."
   **Screen:** click the Scenes mode (Rooftop Cafe, Gym Mirror); hover New Scene.
3. "Pick a scene and click Generate. You get four variants of your influencer in it."
   **Screen:** click Rooftop Cafe → Generate. Cut the wait; show the 4 variants.
4. "Give a thumbs up or down to each one, then use your favourite in a post."
   **Screen:** thumbs up on one, thumbs down on another; hover Use In Post.

## 27 · Motion Control  (AI Influencers)

1. "Motion Control makes your influencer move like the person in any video."
   **Screen:** `/influencers/videos/49`.
2. "Open Influencers, Generate Videos, then Motion Control."
   **Screen:** click the Motion Control sub-tab.
3. "Choose a motion video from your Library. It can be 3 to 30 seconds long."
   **Screen:** click the video picker → pick Marco's 6-second video; show its length.
4. "Choose a first frame, or click Make First Frame to recreate the video's opening shot with her in it."
   **Screen:** click Make First Frame. Cut the wait; show the first frame with Sienna in it.
5. "Pick 720p or 1080p and click Generate Video. The result is as long as the motion video."
   **Screen:** pick 720p, click Generate Video. Cut the wait; play the result.

## 28 · Replace a Character in a Video  (AI Influencers)

1. "Replace Character puts your influencer in place of one person in a video you own."
   **Screen:** `/influencers/videos/49`.
2. "Open Influencers, Generate Videos, then Replace Character."
   **Screen:** click the Replace Character sub-tab.
3. "Choose a source video of up to 15 seconds and say who to replace."
   **Screen:** pick Marco's video; type *the man in the mustard jacket*.
4. "Choose whether she keeps the video's outfit, and whether other people and on-screen text are left alone."
   **Screen:** toggle Keep The Outfit on; hover the "leave others alone" toggle.
5. "Confirm that you own the video or have the rights to use it, then click Replace Character."
   **Screen:** tick the rights box, click Replace Character. Cut the wait; play the result.

## 29 · Create a Dialogue Scene  (AI Influencers)

1. "A scene is one continuous take where your influencer speaks the lines you write."
   **Screen:** `/influencers/videos/49`.
2. "Open Influencers, Generate Videos, then Scene."
   **Screen:** click the Scene sub-tab.
3. "Add a second character if you want one: another influencer, or someone you describe."
   **Screen:** open the "who" chip → Someone You Describe → *Marco, 30, short curly dark hair, mustard corduroy jacket*.
4. "Write each line, choose who says it, and add an acting cue or a pronunciation note where it helps."
   **Screen:** Where It Happens: *A small cafe terrace in Lisbon, late afternoon*. Script:
   `Sienna: (laughing) You actually finished it?` / `Marco: Last page this morning. Don't look so surprised.` / `Sienna: (quietly) I'm not surprised. I'm proud.`
5. "Start with Draft to check the take, then generate it again as Final."
   **Screen:** open the quality chip → Draft → Generate. Cut the wait; play it; hover the quality chip (Final).

## 30 · Export a Frame From a Video  (Content Studio)

1. "Any moment of a video can become an image."
   **Screen:** Content Studio → Library tab.
2. "Open a video in your Library and scrub to the moment you want."
   **Screen:** click Marco's video → File Details → drag the player to ~2.5 s.
3. "Click Export This Frame. The image is saved to your Library."
   **Screen:** click Export This Frame; hold on the success toast.
4. "From there you can edit it or use it as the source for Replicate Photo."
   **Screen:** hover Edit, then Replicate in File Details.

## 31 · Give Your Influencer a Voice  (AI Influencers)

1. "A voice lets your influencer speak in videos and audio."
   **Screen:** `/influencers/create/49`.
2. "Open Influencers, then Voice."
   **Screen:** click the Voice tab (Her Voices).
3. "Under Design A Voice, describe her age and vibe, pick a keyword, and set her accent by city and country."
   **Screen:** Age & vibe *25, warm and unhurried*; pick a keyword; City *Lisbon*; Country *Portugal*.
4. "Click Design Voice. Listen to the three candidates and save the one you like."
   **Screen:** click Design Voice. Cut the wait; play the first candidate for ~2 s; click Save on it.
5. "To make audio, write a script, add audio tags or click Enhance, and click Generate Speech. Save the take you prefer to your Library."
   **Screen:** Text To Speech tab → type *Morning. I finally finished the sketchbook, and I am showing the last three pages tonight.* → click Enhance (cut) → Generate Speech (cut) → Save on a take.

## 32 · Make a Talking Video  (AI Influencers)  — record after 31

1. "A talking video is a close-up of your influencer speaking your script."
   **Screen:** `/influencers/videos/49`.
2. "Open Influencers, Generate Videos, then Talking."
   **Screen:** click the Talking sub-tab.
3. "Choose a close-up image with a clear face."
   **Screen:** click the image picker → Sienna's café photo.
4. "Write the script, or switch to Audio File and choose one from your Library."
   **Screen:** type *Hi. Quick one before I head out: the new sketchbook pages go up tonight at eight.*; hover the Audio File switch.
5. "Click Generate Video. Long scripts are rendered in parts and joined for you."
   **Screen:** click Generate Video. Cut the wait; play the result.

## 33 · Edit Clips Together  (Content Studio)

1. "The clip editor joins your videos and images into one finished video."
   **Screen:** `/studio`.
2. "In Content Studio, click Action, then New Edit."
   **Screen:** Action → New Edit; name it *Sketchbook teaser*.
3. "Add clips and stills, drag them into order, and trim each one."
   **Screen:** Add Clip Or Image twice (a video, then a photo); drag the second above the first; hover a trim field.
4. "Add text and image overlays, and an audio track if you want one."
   **Screen:** Add Text → *new pages tonight*; hover Add PNG and Choose Audio.
5. "Pick 9:16 or 3:4 and click Export. The finished video is saved to your Library."
   **Screen:** click 9:16, click Export. Cut the wait; show "saved to your Library".

## 34 · Run a Launch Campaign  (Content Studio)

1. "A launch campaign is a run of posts and messages that build up to one launch."
   **Screen:** `/audience`.
2. "Open Audience and click Launch Campaign."
   **Screen:** click Launch Campaign (top right).
3. "Say what you are launching, pick the launch time and how many days of anticipation you want."
   **Screen:** type *New photo set from Lisbon, 24 images*; hover Launch Time; set Days Of Anticipation to 3.
4. "Click Write Drafts. Edit any post or message, or clear one to leave it out."
   **Screen:** click Write Drafts. Cut the wait; click into one draft and add *Save your spot.*
5. "Click Schedule Campaign. Every post and message is scheduled at once."
   **Screen:** click Schedule Campaign; hold on the confirmation. (Delete the campaign's posts afterwards if you don't want them to go out.)

## 35 · Caption Modes, Stories and AI Disclosure  (Content Studio)

1. "When you write a post, pick a caption mode next to Write a Caption: Standard, Continuation, Comment Bait or Hook Overlay."
   **Screen:** New Post → attach Sienna's café photo → open the caption mode select → choose Hook Overlay.
2. "Hook Overlay also gives you a short line to put on the video itself."
   **Screen:** click Write It For Me. Cut the wait; show the hook line and its Copy button.
3. "In Distribution, tick an Instagram or Facebook account and switch on Post As Story to send 9:16 media as a Story."
   **Screen:** Distribution section → tick the Instagram account → switch on Post As Story. (No account connected: hover Distribution only.)
4. "Posts with AI media carry an AI disclosure on every platform. You can switch it off per post."
   **Screen:** scroll to the AI Disclosure row; toggle it off and on.

---
title: "November 27th, 2020 Video Jace Talk: When there's a new feature in an engine doesn't mean you can use it"
date: "2020-11-27"
layout: transcript
topics:
    - "technology/unreal-engine"
---
# [November 27th, 2020 Video](../2020-11-27.md)
## Jace Talk: When there's a new feature in an engine doesn't mean you can use it
https://youtube.com/embed/0kmDHBWf640?autoplay=1&start=154&end=218

### Topics
* [Technology > Unreal Engine](../topics/technology/unreal-engine.md)

### Transcript

> hey everyone my name's jace i'm a community manager at coffee stain studios and today i'm going to be talking to you about the upcoming engine upgrade update and um it was going to come out next week but it's been pushed back a week or two so we'll see i'll let you know close to the date when it's going to be coming out and um and all that stuff but i want to talk about it now anyway because one i already wrote the script i got everything prepared but also two um the the point the other point of this video was i wanted to explain what an engine upgrade is and what um why we do it and what you can expect from this engine upgrade uh and it hopefully it will give you a better idea of what you can expect from engine upgrades in general from games so yeah we'll see how that goes so this this um talk will be a little bit on the technical side i think so some people who uh aren't into game dev or unreal engine 4 specifically might not understand everything that's going on but i will wrap everything up into what what what's the outcome going to be what will you experience as a gamer uh as a player of satisfactory uh so it'll it'll hopefully still make sense why do we upgrade the engine well if you look at these patch notes that's a lot of patch notes i'd hate to be the person who writes them i'm sure they've got some means of automating it but still there's proofreading i don't know a nightmare that's a lot right and this is just one update um and we are updating from 4.22.3 up to 4.25 so we are going up three versions of the engine so take what you just saw or multiply that by three and that's how many changes have happened in um within this gap that we're taking now this is a little scary because uh things change sometimes you have to go in and make a lot of changes to the code yourself to make it compatible with the with how they want things done in the new version of the engine we also modify the engine ourselves to make satisfactory so we have to see if our modifications are compatible with their modifications as well um so then that takes a lot of testing and hopefully everything will work and then when it goes to experimental you guys will let us know if it doesn't yeah engine upgrades are a big deal they're a really big task they're scary and we've got to do it because uh first of all sometimes the engine upgrades give us uh some new tools that make things easier for us to work new tools that make things look or work better so you can get things that look better or you know better performance sometimes the performance is just free the engine is just better now and now the game's quicker sometimes they give us access to certain things or they've changed the way that they do things that if we can take if we are able to take advantage of that in our game we will get those performances too now this is a misconception with engines and a good example on london 5 is that when when there's a new feature in an engine that doesn't mean you can use it uh automatically especially if you've got an existing game that's been running on things before it doesn't necessarily mean you can utilize it and even if they say it just works it doesn't just work so like the there's big new things like in an engine 5 was it like lumen or something like that or lumen not only reacts to moving light sources but also changes in geometry if we upgrade to under engine 5 we don't just get that you know like you have to make you have to support it and that takes a lot of work especially when you've been working on a game that works a specific way for so long so we still have to go through those patch notes and see what can we take advantage of and we were able to take advantage of a lot of things and gain some performance upgrades uh from that so today i'm going to talk to you about a few of those things just a couple things not everything that we get from this upgrade but just some of them and in what way that will impact you as a player of satisfactory so the first thing that i want to talk about is packet reordering so that's been added to the engine now and what does it mean well by packet they're referring to network packages um so you guys have probably heard of thing called packet loss which is when your little packets of data gets sent to your friend and they don't make it um or they're discarded for one reason or another so packet reordering what that helps do is reduce the amount of packets that are lost so if um and in this case it's uh if multiple packets arrive out of order within the same frame they can now reorder the packets so that it makes sense so they don't have to discard it whereas typically when when packets arrive out of order the engine won't know what to do with those packets so it discards it and just like says can you send it again please yeah so this is going to help a lot with multiplayer performance essentially so that's like a free win we get there yay yeah so the next thing is improved input output speeds uh so this means yeah basically just quicker loading saving streaming things like that so hopefully autosave hitches will be less severe uh hopefully hitches that you get as you navigate around the world as the game loads in new tiles or whatever hopefully that that kind of stuff should be um you should get a performance upgrade there so cool uh next thing is a a pretty technical one it's um for those of you who know unreal engine 4 will this will make some sense i guess but i'll also talk about the implications of what that means for everyone and that is you property has been refactored to f property what this means is that everything that was tagged as you properties before came with the overhead of being an object when maybe it didn't need to be so this essentially means that things that were your properties before will be smaller because it contains less information they won't necessarily be considered an object and they won't be counted to uh the object list meaning iterating over objects as the game plays or whatever and this also means if there's less objects that means the build limit that people have um run into which is the it's not a build limit but it's a an object limit the object limit doesn't change but the number of objects in the game will reduce meaning you can build more so essentially what this means is we will go through and do the refactor and that means you can build more objects and an overall performance improvement uh now this next one's really cool and i've tried to explain this and it's super hard to explain so i'm gonna do my best i i hope everyone can understand it uh and this is her instance custom data and i think this is amazing first of all the first thing you need to know is what an instance is and an instance is just a copy so if you build two constructors you have two instances of a constructor right so they're just copies and where the big win is here is on the rendering side of things renderers are really good at doing the same thing over and over without interruption or switching tasks so what you want to do is when you have all your constructors built for example you want them all to be instances or you want them to be considered instances by your renderer so that it will take constructor data what it needs to draw a constructor and then it'll draw one and then it'll go there's another one it's the same thing all right goes little dj khaled on our ass all right it's like there's another one it's a constructor i already have that data we'll draw it and then just draw all the constructors then throw that away and then move on to the next next task what you don't want is uh for it to see two different constructors and think that they're different things therefore treating them as different tasks therefore requesting the same data over and over again now this can happen when let's say you paint them a different color with the color gun the way we added the color gun into the game is we had a separate material on each unique object for every color in the color gun so there are 16 colors in the color gun every single unique object every different kind of wall foundation had 16 materials on it one for each slot of the color gun so that when you paint it it will use the the appropriate material that you had selected in your color gun now to you and me we still see the constructors as instances they're still copies they're the same thing they just look a little different the renderer sees two constructors with different materials on therefore different objects so it won't draw them one after the other it will separate them into tasks now that we can use per instance custom data or primitive data on each constructor if we keep going with this example it means we can just put one material on every constructor and have the renderer look at that that data that custom data on each instance as it draws them and put it into the material and then draw so basically it means we can draw all the constructors without being interrupted and then move on to the next thing and draw all of those without being interrupted even if you use different colors between them this also adds
>
> [Music]
>
> another really big thing that isn't going to be in this upgrade but will come in the future and that is we had a 16 color color palette on the color gun before because that was just a limitation we imposed on ourselves but if we only need to use one material per object and the custom data is on the object then that data could just be a color it could be any color and that means you could have infinite colors so this one thing gives us a massive performance increase and it's going to allow us to expand upon the color gun now some of you might think well but i like presets presets are really cool uh because sometimes it makes it easy to change the color scheme of my factory uh the preset functionality will remain we'll keep that but we will also add in the future the ability to add um yeah any any color any number of colors to your factory uh i think that's going to be really cool so i hope that makes sense i really hope i did it right um i haven't been a programmer for a while so if i messed up my explanation don't go too hard on me all right i did my best i make shitty youtube videos now all right leave me alone they've added a better gloss shader so we can make um yeah basically it's just a more performance gloss shader so uh for those of you out there who have gloss buildings you're going to have it's going to perform better for you moving forward um yeah that's nice now i don't know if that's going to actually be in when the update comes out but if it doesn't it will be added after at some point the next thing is screen space global illumination so that's gonna be there's like a beta version of that in the engine now so we're gonna be um experimenting with that essentially it's just gonna affect the lighting of the game uh so we'll see we'll see do we like what it does um and then can we afford to actually have it in the game and so this is something that happens with updates is um with engine upgrades is sometimes we there are things that we just need to experiment with they sound cool we need to see uh you know is it is it gonna is it something that we want to have if yes can we afford to have it like is it expensive like is it really heavy to use or whatever you know we have to experiment with some of these things so so one of the things that we want to add is um there's also another thing that's been introduced it's this like weird atmosphere thing it's a new system that's been introduced by epic which uh gives a better way to render atmosphere so this will affect lighting so some of you like to build really high up um this kind of thing might change the way that the lighting reacts as you go further uh further up uh in the world further up into the atmosphere so yeah it'll sort of just emulate an atmosphere a little bit better uh this might also uh alleviate some of the issues with fog that people are having as well if we were able to do that so this is something that i think we're we're gonna get it in to some degree but we'll have to see um yeah we'll see what it does and if it's useful or not but i don't think it'll be in this update anyway so that's a lot of talking i i hope that was interesting i hope it made sense please let me know if it did or if it didn't so you can look forward to the update in a couple weeks one thing i want to say is don't forget about our streams oh no at twitch.tv coffeescene studios devs last week this tuesday that just went by we had a special guest it was let's game it out the guy who makes offensively bad factories he is every game developer's worst nightmare he's uh youtube him please it's scary and it was a pleasure to have him on on the on the stream and next week we're going to have another special guest who's going to be an employee at coffee stand who is leaving coffee stain but i really really want him to jump on stream and i really want you guys to meet him because he has never wanted to be in front of the camera or public facing at all ever but he has been there by my side doing the community things since you know almost day one and all of the cool things that happened all the amazing moments that we had uh in this community he was there involved in in every single one so if if if you've had a good time in the satisfactory community i i ask please come along on tuesday and say a hello and thank you to uh this person so see you then hopefully if you like this video like comment and subscribe social media bye
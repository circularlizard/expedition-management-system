# Phases  
  
## 1 - Admin views  
Admins only  
Read only from OSM  
Explorer / expedition data structure in Wordpress  
- assign routes, leader in charge, WhatsApp groups  
Populated from flexi record, event and member data  
See who is in which expedition and team, first aid status, training status  
View by explorer or by team or by expedition or by unit (patrol)  
Reference data like patrols, seee section ids  
Download sheet  
  
Useful views - gets the basic OSM linkage in place  
  
Issues to solve  
* OSM - Parent / Explorer linkage, when parent has logged in on Explorers’ behalf for the training — if we can’t find training records we need a fallback  
* How much data are we going to store, what’s the minimum that we need  
  
  
## 2 - explorer view  
Explorer - login and see personal status  
Training record  
- can we mark courses as required  
Expedition assignment  
- team members   
- Route info  
- Leader in charge details  
- WhatsApp groups   
  
Doesn’t need any live osm data access, to insulate rate limits  
  
Issues to solve  
* OSM - Parent / Explorer linkage  
    * Case 1 - the explorer logs in, we have them on our list  
    * Case 2 - the parent logs in, we can identify which explorer  
    * Case 3 - someone logs in with OSM but we can’t link them to anyone on our list  
* Additional support needs - what to do if the Explorer cannot do the courses eg dyslexic, autistic  
* What are parents able to do on behalf of their kids  
  
  
## 3 - signup  
Use gravity forms to build a sign up process  
  

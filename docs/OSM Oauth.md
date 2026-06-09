URLs
Authorisation
https://www.onlinescoutmanager.co.uk/oauth/authorize
If you are using the 'authorization code flow', your client library will build a link based on this URL that your users will click - this will bring them to OSM where they will be asked to log in. If they log in and authorise your application, they will be redirected back to the Redirect URL you specify.

Access token
https://www.onlinescoutmanager.co.uk/oauth/token
When the user has been redirected back to your application, your client library will make a request to this URL to get an 'access token' and a 'refresh token' - these should be stored in your database.

Resource owner
https://www.onlinescoutmanager.co.uk/oauth/resource
This will provide you with the user's full name, email address, and a list of sections that your application can access.
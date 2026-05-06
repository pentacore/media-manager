# OLD/BLOCKED
- [ ] The AI Price Refresh should queue up a job to run in the background
    - [x] To begin with, we can just queue up a job and have it run in the background (Somehow we need the frontend to know if the job is done or not, so we cant queue up multiple jobs if one is already running, and we can show a loading state on the refresh button while the job is running)
    - [ ] (Awaiting support in the prisma-php/prisma package for batching) maybe make use of batching if the model supports it (Then we will also need a job that regularly checks the job status, and calls any tools it requests and then posts the updated data), also allow setting a specific model to use for this functionality.
# NEW
- [ ] The "Top users" section in the watch history shows the wrong user (Looks like its always the first user in the list)
- [ ] The "Watched" column in the watch history shows the wrong data, always shows the full length
- [ ] Total watchtime doesnt seem to show the correct data either
- [ ] Make use of inertia pre-fetching to speed up page loading times (This will require some changes to the way we load data for the pages, but it should be worth it in the end)
- [ ] Maybe have a job that keeps the cached data from sonarr, radarr, seerr warm as long as theres a user on the page (Maybe make use of the websocket connection to keep track of this)
    

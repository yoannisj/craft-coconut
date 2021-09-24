# Craft Coconut

Transcode your [Craft](https://www.craftcms.com) video assets with [Coconut.co](https://coconut.co/).

## Manual Testing

1. `docker-compose up -d`
2. [http://localhost:8080](http://localhost:8080/admin)
    - username: `craftcms`
    - password: `craftcms2018!!`

## Todo

- [ ] Better job error handling
- [ ] Preserve `coconutJobId` value in database (check if it is of any use after the job has completed)?
- [ ] Implement and test the upload controller action
- [ ] Implement and test the webhook controller action
- [ ] Better management of output files
    - 1. Save output files into same volume as source (make sure they are not indexed as assets, e.g. when running the "update Asset index" utility)
    - 2. Delete file in output record's `afterDelete()` method
    - 3. Check if output record was deleted in upload action, and don't create corresponding file
    - 4. Check if output record was deleted in webhook action, and remove corresponding file from volume (careful if newer job uses the output url)
- [ ] Check asset file modified date in `TranscodeVideo` action
- [ ] Implement "Transcode Video Assets" utility
- [ ] Implement "Clear Video Outputs" utility

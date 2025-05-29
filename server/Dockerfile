FROM node:lts-alpine

RUN apk --no-cache add curl

WORKDIR /app

COPY package*.json ./

RUN npm install

RUN npm i -g nodemon

COPY . .

EXPOSE 3000

CMD ["npm", "run", "start"]
